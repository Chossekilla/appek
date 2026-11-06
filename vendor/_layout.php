<?php
/**
 * 🎨 VENDOR MASTER LAYOUT — sdílený topbar pro všechny vendor stránky.
 *
 * Použití:
 *   require_once __DIR__ . '/_lib.php';
 *   require_once __DIR__ . '/_layout.php';
 *   $user = vendor_require_login();
 *   vendor_render_topbar($user, 'access');  // <- aktivní tab
 *   // ... obsah stránky ...
 */

/**
 * 🆕 v2.9.61 — Verze APPEK produktu pro vendor topbar/footer.
 * Vendor nemá APP_VERSION konstantu (ta je v api/config.php produktu).
 * Přečteme ji z api/config.php textově (bez include cizího PHP).
 */
function vendor_appek_version(): string {
    static $cache = null;
    if ($cache !== null) return $cache;

    // 1) Soubor vendor/.appek-version — zapisuje ho self-update po každém deploy.
    //    Nejspolehlivější na produkci, kde vendor.appek.cz je samostatný docroot.
    $verFile = __DIR__ . '/.appek-version';
    if (is_file($verFile)) {
        $v = trim((string) @file_get_contents($verFile));
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+/', $v)) { $cache = $v; return $cache; }
    }

    // 2) Konstanta APP_VERSION (pokud je config.php includnutý)
    if (defined('APP_VERSION')) { $cache = APP_VERSION; return $cache; }

    // 3) api/config.php — funguje lokálně / na MASTER serveru s api/ vedle vendor/
    foreach ([__DIR__ . '/../api/config.php', __DIR__ . '/api/config.php'] as $p) {
        if (is_file($p)) {
            $txt = @file_get_contents($p);
            if ($txt && preg_match("/APP_VERSION[^']*'([^']+)'/", $txt, $m)) {
                $cache = $m[1];
                return $cache;
            }
        }
    }

    // 4) Poslední záchrana — nejvyšší publikovaná verze z vendor_updates DB
    if (function_exists('vendor_db')) {
        try {
            $v = vendor_db()->query(
                "SELECT version FROM vendor_updates WHERE status='published' ORDER BY id DESC LIMIT 1"
            )->fetchColumn();
            if ($v) { $cache = $v; return $cache; }
        } catch (Throwable $e) {}
    }

    $cache = '?';
    return $cache;
}

/**
 * 🆕 v2.0.86 — Český kalendář jmenin (klíč = "D.M", hodnota = jméno).
 * Použito v topbaru pro pill „🎉 svátek má X".
 */
function vendor_svatky_cz_map(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [
        '1.1'=>'Nový rok','2.1'=>'Karina','3.1'=>'Radmila','4.1'=>'Diana','5.1'=>'Dalimil','6.1'=>'Tři králové / Kašpar, Melichar, Baltazar','7.1'=>'Vilma','8.1'=>'Čestmír','9.1'=>'Vladan','10.1'=>'Břetislav','11.1'=>'Bohdana','12.1'=>'Pravoslav','13.1'=>'Edita','14.1'=>'Radovan','15.1'=>'Alice','16.1'=>'Ctirad','17.1'=>'Drahoslav','18.1'=>'Vladislav','19.1'=>'Doubravka','20.1'=>'Ilona','21.1'=>'Běla','22.1'=>'Slavomír','23.1'=>'Zdeněk','24.1'=>'Milena','25.1'=>'Miloš','26.1'=>'Zora','27.1'=>'Ingrid','28.1'=>'Otýlie','29.1'=>'Zdislava','30.1'=>'Robin','31.1'=>'Marika',
        '1.2'=>'Hynek','2.2'=>'Nela','3.2'=>'Blažej','4.2'=>'Jarmila','5.2'=>'Dobromila','6.2'=>'Vanda','7.2'=>'Veronika','8.2'=>'Milada','9.2'=>'Apolena','10.2'=>'Mojmír','11.2'=>'Božena','12.2'=>'Slavěna','13.2'=>'Věnceslav','14.2'=>'Valentýn','15.2'=>'Jiřina','16.2'=>'Ljuba','17.2'=>'Miloslava','18.2'=>'Gizela','19.2'=>'Patrik','20.2'=>'Oldřich','21.2'=>'Lenka','22.2'=>'Petr','23.2'=>'Svatopluk','24.2'=>'Matěj','25.2'=>'Liliana','26.2'=>'Dorota','27.2'=>'Alexandr','28.2'=>'Lumír','29.2'=>'Horymír',
        '1.3'=>'Bedřich','2.3'=>'Anežka','3.3'=>'Kamil','4.3'=>'Stela','5.3'=>'Kazimír','6.3'=>'Miroslav','7.3'=>'Tomáš','8.3'=>'Gabriela','9.3'=>'Františka','10.3'=>'Viktorie','11.3'=>'Anděla','12.3'=>'Řehoř','13.3'=>'Růžena','14.3'=>'Rút / Matylda','15.3'=>'Ida','16.3'=>'Elena / Herbert','17.3'=>'Vlastimil','18.3'=>'Eduard','19.3'=>'Josef','20.3'=>'Světlana','21.3'=>'Radek','22.3'=>'Leona','23.3'=>'Ivona','24.3'=>'Gabriel','25.3'=>'Marián','26.3'=>'Emanuel','27.3'=>'Dita','28.3'=>'Soňa','29.3'=>'Taťána','30.3'=>'Arnošt','31.3'=>'Kvido',
        '1.4'=>'Hugo','2.4'=>'Erika','3.4'=>'Richard','4.4'=>'Ivana','5.4'=>'Miroslava','6.4'=>'Vendula','7.4'=>'Heřman / Hermína','8.4'=>'Ema','9.4'=>'Dušan','10.4'=>'Darja','11.4'=>'Izabela','12.4'=>'Julius','13.4'=>'Aleš','14.4'=>'Vincenc','15.4'=>'Anastázie','16.4'=>'Irena','17.4'=>'Rudolf','18.4'=>'Valérie','19.4'=>'Rostislav','20.4'=>'Marcela','21.4'=>'Alexandra','22.4'=>'Evžénie','23.4'=>'Vojtěch','24.4'=>'Jiří','25.4'=>'Marek','26.4'=>'Oto','27.4'=>'Jaroslav','28.4'=>'Vlastislav','29.4'=>'Robert','30.4'=>'Blahoslav',
        '1.5'=>'Svátek práce','2.5'=>'Zikmund','3.5'=>'Alexej','4.5'=>'Květoslav','5.5'=>'Klaudie','6.5'=>'Radoslav','7.5'=>'Stanislav','8.5'=>'Den vítězství','9.5'=>'Ctibor','10.5'=>'Blažena','11.5'=>'Svatava','12.5'=>'Pankrác','13.5'=>'Servác','14.5'=>'Bonifác','15.5'=>'Žofie','16.5'=>'Přemysl','17.5'=>'Aneta','18.5'=>'Nataša','19.5'=>'Ivo','20.5'=>'Zbyšek','21.5'=>'Monika','22.5'=>'Emil','23.5'=>'Vladimír','24.5'=>'Jana','25.5'=>'Viola','26.5'=>'Filip','27.5'=>'Valdemar','28.5'=>'Vilém','29.5'=>'Maxmilián','30.5'=>'Ferdinand','31.5'=>'Kamila',
        '1.6'=>'Laura','2.6'=>'Jarmil','3.6'=>'Tamara','4.6'=>'Dalibor','5.6'=>'Dobroslav','6.6'=>'Norbert','7.6'=>'Iveta / Slavoj','8.6'=>'Medard','9.6'=>'Stanislava','10.6'=>'Gita','11.6'=>'Bruno','12.6'=>'Antonie','13.6'=>'Antonín','14.6'=>'Roland','15.6'=>'Vít','16.6'=>'Zbyněk','17.6'=>'Adolf','18.6'=>'Milan','19.6'=>'Leoš','20.6'=>'Květa','21.6'=>'Alois','22.6'=>'Pavla','23.6'=>'Zdeňka','24.6'=>'Jan','25.6'=>'Ivan','26.6'=>'Adriana','27.6'=>'Ladislav','28.6'=>'Lubomír','29.6'=>'Petr a Pavel','30.6'=>'Šárka',
        '1.7'=>'Jaroslava','2.7'=>'Patricie','3.7'=>'Radomír','4.7'=>'Prokop','5.7'=>'Cyril a Metoděj','6.7'=>'M. J. Hus','7.7'=>'Bohuslava','8.7'=>'Nora','9.7'=>'Drahoslava','10.7'=>'Libuše / Amálie','11.7'=>'Olga','12.7'=>'Bořek','13.7'=>'Markéta','14.7'=>'Karolína','15.7'=>'Jindřich','16.7'=>'Luboš','17.7'=>'Martina','18.7'=>'Drahomíra','19.7'=>'Čeněk','20.7'=>'Ilja','21.7'=>'Vítězslav','22.7'=>'Magdaléna','23.7'=>'Libor','24.7'=>'Kristýna','25.7'=>'Jakub','26.7'=>'Anna','27.7'=>'Věroslav','28.7'=>'Viktor','29.7'=>'Marta','30.7'=>'Bořivoj','31.7'=>'Ignác',
        '1.8'=>'Oskar','2.8'=>'Gustav','3.8'=>'Miluše','4.8'=>'Dominik','5.8'=>'Kristián','6.8'=>'Oldřiška','7.8'=>'Lada','8.8'=>'Soběslav','9.8'=>'Roman','10.8'=>'Vavřinec','11.8'=>'Zuzana','12.8'=>'Klára','13.8'=>'Alena','14.8'=>'Alan','15.8'=>'Hana','16.8'=>'Jáchym','17.8'=>'Petra','18.8'=>'Helena','19.8'=>'Ludvík','20.8'=>'Bernard','21.8'=>'Johana','22.8'=>'Bohuslav','23.8'=>'Sandra','24.8'=>'Bartoloměj','25.8'=>'Radim','26.8'=>'Luděk','27.8'=>'Otakar','28.8'=>'Augustýn','29.8'=>'Evelína','30.8'=>'Vladěna','31.8'=>'Pavlína',
        '1.9'=>'Linda / Samuel','2.9'=>'Adéla','3.9'=>'Bronislav','4.9'=>'Jindřiška','5.9'=>'Boris','6.9'=>'Boleslav','7.9'=>'Regína','8.9'=>'Mariana','9.9'=>'Daniela','10.9'=>'Irma','11.9'=>'Denisa','12.9'=>'Marie','13.9'=>'Lubor','14.9'=>'Radka','15.9'=>'Jolana','16.9'=>'Ludmila','17.9'=>'Naděžda','18.9'=>'Kryštof','19.9'=>'Zita','20.9'=>'Oleg','21.9'=>'Matouš','22.9'=>'Darina','23.9'=>'Berta','24.9'=>'Jaromír','25.9'=>'Zlata','26.9'=>'Andrea','27.9'=>'Jonáš','28.9'=>'Den české státnosti / Václav','29.9'=>'Michal','30.9'=>'Jeroným',
        '1.10'=>'Igor','2.10'=>'Olívie / Oliver','3.10'=>'Bohumil','4.10'=>'František','5.10'=>'Eliška','6.10'=>'Hanuš','7.10'=>'Justýna','8.10'=>'Věra','9.10'=>'Štefan / Sára','10.10'=>'Marina','11.10'=>'Andrej','12.10'=>'Marcel','13.10'=>'Renáta','14.10'=>'Agáta','15.10'=>'Tereza','16.10'=>'Havel','17.10'=>'Hedvika','18.10'=>'Lukáš','19.10'=>'Michaela','20.10'=>'Vendelín','21.10'=>'Brigita','22.10'=>'Sabina','23.10'=>'Teodor','24.10'=>'Nina','25.10'=>'Beáta','26.10'=>'Erik','27.10'=>'Šarlota / Zoe','28.10'=>'Den vzniku ČSR','29.10'=>'Silvie','30.10'=>'Tadeáš','31.10'=>'Štěpánka',
        '1.11'=>'Felix','2.11'=>'Památka zesnulých','3.11'=>'Hubert','4.11'=>'Karel','5.11'=>'Miriam','6.11'=>'Liběna','7.11'=>'Saskie','8.11'=>'Bohumír','9.11'=>'Bohdan','10.11'=>'Evžen','11.11'=>'Martin','12.11'=>'Benedikt','13.11'=>'Tibor','14.11'=>'Sáva','15.11'=>'Leopold','16.11'=>'Otmar','17.11'=>'Den boje za svobodu a demokracii','18.11'=>'Romana','19.11'=>'Alžběta','20.11'=>'Nikola','21.11'=>'Albert','22.11'=>'Cecílie','23.11'=>'Klement','24.11'=>'Emílie','25.11'=>'Kateřina','26.11'=>'Artur','27.11'=>'Xenie','28.11'=>'René','29.11'=>'Zina','30.11'=>'Ondřej',
        '1.12'=>'Iva','2.12'=>'Blanka','3.12'=>'Svatoslav','4.12'=>'Barbora','5.12'=>'Jitka','6.12'=>'Mikuláš','7.12'=>'Ambrož / Benjamin','8.12'=>'Květoslava','9.12'=>'Vratislav','10.12'=>'Julie','11.12'=>'Dana','12.12'=>'Simona','13.12'=>'Lucie','14.12'=>'Lýdie','15.12'=>'Radana','16.12'=>'Albína','17.12'=>'Daniel','18.12'=>'Miloslav','19.12'=>'Ester','20.12'=>'Dagmar','21.12'=>'Natálie','22.12'=>'Šimon','23.12'=>'Vlasta','24.12'=>'Štědrý den / Adam a Eva','25.12'=>'1. svátek vánoční','26.12'=>'2. svátek vánoční / Štěpán','27.12'=>'Žaneta','28.12'=>'Bohumila','29.12'=>'Judita','30.12'=>'David','31.12'=>'Silvestr',
    ];
    return $cache;
}

function vendor_nav_groups(): array {
    // Jedno menu (dřív 2 řady + rychlé akce + hub karty na dashboardu = každý cíl 3×).
    return [
        ['label' => null, 'items' => [
            ['key' => 'dashboard', 'label' => 'Přehled', 'url' => 'index.php', 'ico' => 'home'],
        ]],
        ['label' => 'Prodej', 'items' => [
            ['key' => 'shop',          'label' => 'Objednávky', 'url' => 'shop.php',          'ico' => 'cart', 'cnt' => 'orders'],
            ['key' => 'shop-coupons',  'label' => 'Kupony',     'url' => 'shop-coupons.php',  'ico' => 'ticket'],
            ['key' => 'faktury',       'label' => 'Faktury',    'url' => 'faktury.php',       'ico' => 'doc'],
        ]],
        ['label' => 'Produkt', 'items' => [
            ['key' => 'packages',      'label' => 'Balíčky',    'url' => 'packages.php',      'ico' => 'box'],
            ['key' => 'licenses',      'label' => 'Licence',    'url' => 'licenses.php',      'ico' => 'key', 'cnt' => 'licenses'],
            ['key' => 'pages-editor',  'label' => 'Web',        'url' => 'pages-editor.php',  'ico' => 'globe'],
        ]],
        ['label' => 'Provoz', 'items' => [
            ['key' => 'updates',       'label' => 'Vydání pro zákazníky', 'url' => 'updates.php', 'ico' => 'up'],
            ['key' => 'demo-log',      'label' => 'Demo',       'url' => 'demo-log.php',      'ico' => 'flask'],
            ['key' => 'access',        'label' => 'Přístupy',   'url' => 'access.php',        'ico' => 'users'],
            ['key' => 'pirate',        'label' => 'Piráti',     'url' => 'pirate.php',        'ico' => 'shield', 'cnt' => 'pirate'],
        ]],
        ['label' => 'Systém', 'items' => [
            ['key' => 'business-info', 'label' => 'Firma',      'url' => 'business-info.php', 'ico' => 'firm'],
            ['key' => 'settings',      'label' => 'Nastavení',  'url' => 'settings.php',      'ico' => 'cog'],
            ['key' => 'self-update',   'label' => 'Aktualizace vendoru', 'url' => 'self-update.php', 'ico' => 'refresh'],
        ]],
    ];
}

function vendor_nav_items(): array {
    // Flat list — zpětná kompatibilita.
    $out = [];
    foreach (vendor_nav_groups() as $g) foreach ($g['items'] as $it) $out[] = $it;
    return $out;
}

/** Počty do menu — stejné dotazy jako KPI na dashboardu (index.php). Soft-fail. */
function vendor_nav_counts(): array {
    $c = ['orders' => 0, 'licenses' => 0, 'pirate' => 0];
    try {
        $pdo = vendor_db();
        try { $c['orders'] = (int) $pdo->query("SELECT COUNT(*) FROM vendor_shop_orders WHERE payment_status='pending'")->fetchColumn(); } catch (Throwable $e) {}
        try { $c['licenses'] = (int) $pdo->query("SELECT COUNT(*) FROM vendor_licenses WHERE status='active' AND expires_at IS NOT NULL AND expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn(); } catch (Throwable $e) {}
        try { $c['pirate'] = (int) $pdo->query("SELECT COUNT(*) FROM vendor_pirate_installs WHERE status IN ('new','contacted','warned')")->fetchColumn(); } catch (Throwable $e) {}
    } catch (Throwable $e) {}
    return $c;
}

/**
 * Render footer for all vendor pages. Voláno explicitně před </body>.
 */
function vendor_render_footer(): void {
    // 🆕 v2.9.61 — verze přes vendor_appek_version() (čte z api/config.php)
    $version = vendor_appek_version();
    $buildDate = defined('APP_BUILD_DATE') ? APP_BUILD_DATE : date('Y-m-d');
    ?>
    <footer class="footer-master">
      <div class="footer-inner">
        <div class="footer-left">
          <strong>APPEK Master</strong> · v<?= htmlspecialchars($version) ?> · <?= htmlspecialchars($buildDate) ?>
          <span class="sep">·</span>
          <a href="index.php">📊 Přehled</a>
          <span class="sep">·</span>
          <a href="settings.php">⚙️ Nastavení</a>
        </div>
        <div class="footer-right">
          <a href="https://appek.cz/obchodni-podminky.html" target="_blank" rel="noopener">VOP</a>
          <span class="sep">·</span>
          <a href="https://appek.cz/zasady-ochrany-soukromi.html" target="_blank" rel="noopener">GDPR</a>
          <span class="sep">·</span>
          <a href="https://appek.cz/" target="_blank" rel="noopener">🌐 appek.cz</a>
          <span class="sep">·</span>
          <a href="index.php?logout=1" style="color:#ff6b6b">Odhlásit</a>
        </div>
      </div>
    </footer>
    <?php
}

/**
 * Render breadcrumbs above page content.
 * Použij na detailních pages (např. licenses.php → detail).
 *
 * @param array $crumbs [['label'=>'Přehled','url'=>'index.php'], ['label'=>'Tady']]
 */
/**
 * Universal back-button — render na vrchu každé subpage.
 * @param string|null $href  cíl tlačítka (default: index.php)
 * @param string|null $label label (default: '← Zpět na přehled')
 */
function vendor_render_back(?string $href = 'index.php', ?string $label = '← Zpět na přehled'): void {
    ?>
    <div class="back-master">
      <a href="<?= htmlspecialchars($href) ?>" class="back-btn">
        <?= htmlspecialchars($label) ?>
      </a>
    </div>
    <?php
}

function vendor_render_breadcrumbs(array $crumbs): void {
    if (empty($crumbs)) return;
    echo '<nav class="breadcrumbs-master" aria-label="Breadcrumb">';
    foreach ($crumbs as $i => $c) {
        $isLast = $i === count($crumbs) - 1;
        if ($isLast || empty($c['url'])) {
            echo '<span class="bc-current">' . htmlspecialchars($c['label']) . '</span>';
        } else {
            echo '<a href="' . htmlspecialchars($c['url']) . '">' . htmlspecialchars($c['label']) . '</a>';
            echo '<span class="bc-sep">›</span>';
        }
    }
    echo '</nav>';
    ?>
    <?php
}

function vendor_render_topbar(array $user, string $currentPage = ''): void {
    $version = vendor_appek_version();
    $svatek = vendor_svatky_cz_map()[(new DateTime('now'))->format('j.n')] ?? '';
    $dny = ['neděle','pondělí','úterý','středa','čtvrtek','pátek','sobota'];
    $now = new DateTime('now');
    $today = $dny[(int) $now->format('w')] . ' ' . $now->format('j. n.') . ($svatek ? ' · ' . $svatek : '');
    $alias = ['demo' => 'demo-log', 'sales-cms' => 'pages-editor'];
    $active = $alias[$currentPage] ?? $currentPage;
    $cnt = vendor_nav_counts();
    $name = (string) ($user['display_name'] ?? $user['username'] ?? '');
    $ini = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $name) ?: 'A', 0, 2));
    $uiv = @filemtime(__DIR__ . '/ui.css') ?: 1;
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="ui.css?v=<?= (int) $uiv ?>">
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
      <symbol id="vx-home" viewBox="0 0 24 24"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></symbol>
      <symbol id="vx-cart" viewBox="0 0 24 24"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.6 12.4a1 1 0 0 0 1 .8h9.7a1 1 0 0 0 1-.8L21 7H6"/></symbol>
      <symbol id="vx-ticket" viewBox="0 0 24 24"><path d="M3 8a2 2 0 0 0 0 4 2 2 0 0 1 0 4v2h18v-2a2 2 0 0 1 0-4 2 2 0 0 0 0-4V6H3z"/><path d="M13 6v12" stroke-dasharray="2 2"/></symbol>
      <symbol id="vx-doc" viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h7M9 17h5"/></symbol>
      <symbol id="vx-box" viewBox="0 0 24 24"><path d="M3 7l9-4 9 4-9 4z"/><path d="M3 7v10l9 4 9-4V7M12 11v10"/></symbol>
      <symbol id="vx-key" viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/></symbol>
      <symbol id="vx-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.2 3 14.8 0 18M12 3c-3 3.2-3 14.8 0 18"/></symbol>
      <symbol id="vx-up" viewBox="0 0 24 24"><path d="M12 19V5M6 11l6-6 6 6M5 21h14"/></symbol>
      <symbol id="vx-flask" viewBox="0 0 24 24"><path d="M9 3h6M10 3v6L4.5 19a1.3 1.3 0 0 0 1.1 2h12.8a1.3 1.3 0 0 0 1.1-2L14 9V3"/><path d="M7 15h10"/></symbol>
      <symbol id="vx-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/></symbol>
      <symbol id="vx-shield" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z"/><path d="M12 8v4M12 16h.01"/></symbol>
      <symbol id="vx-firm" viewBox="0 0 24 24"><path d="M4 21V5l8-3v19M12 9h8v12M7 8h2M7 12h2M7 16h2M15 13h2M15 17h2M2 21h20"/></symbol>
      <symbol id="vx-cog" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/></symbol>
      <symbol id="vx-refresh" viewBox="0 0 24 24"><path d="M20 11a8 8 0 0 0-14.9-3M4 4v4h4M4 13a8 8 0 0 0 14.9 3M20 20v-4h-4"/></symbol>
    </svg>
    <?php $cntSum = (int) $cnt['orders'] + (int) $cnt['licenses'] + (int) $cnt['pirate']; ?>
    <div class="vx-backdrop" onclick="vxMenu(false)"></div>
    <aside class="vx-rail" id="vx-rail" aria-label="Hlavní menu">
      <div class="vx-top">
        <a href="index.php" class="vx-brand"><span class="vx-logo">A</span><span><b>APPEK Master</b><small>v<?= htmlspecialchars($version) ?></small></span></a>
        <button type="button" class="vx-burger" aria-controls="vx-rail" aria-expanded="false" aria-label="Menu" onclick="vxMenu()">
          <svg class="vx-i-open" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
          <svg class="vx-i-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
          <?php if ($cntSum > 0): ?><span class="vx-burger-dot"><?= $cntSum ?></span><?php endif; ?>
        </button>
      </div>
      <div class="vx-today"><?= htmlspecialchars($today) ?></div>
      <div class="vx-sheet" id="vx-sheet"><!-- desktop: display:contents; mobil (≤900px): spodní panel s dlaždicemi -->
      <nav class="vx-nav">
        <?php foreach (vendor_nav_groups() as $g): ?>
          <?php if ($g['label']): ?><div class="vx-grp"><?= htmlspecialchars($g['label']) ?></div><?php endif; ?>
          <?php foreach ($g['items'] as $it):
              $n = isset($it['cnt']) ? (int) ($cnt[$it['cnt']] ?? 0) : 0;
              $cls = ($it['cnt'] ?? '') === 'pirate' ? 'bad' : (($it['cnt'] ?? '') === 'orders' ? 'hot' : ''); ?>
            <a href="<?= htmlspecialchars($it['url']) ?>" class="<?= $active === $it['key'] ? 'on' : '' ?>"><svg><use href="#vx-<?= htmlspecialchars($it['ico']) ?>"/></svg><?= htmlspecialchars($it['label']) ?><?php if ($n > 0): ?><span class="vx-cnt <?= $cls ?>"><?= $n ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </nav>
      <div class="vx-ext"><a href="https://appek.cz/" target="_blank" rel="noopener">appek.cz ↗</a><a href="https://demo.appek.cz/" target="_blank" rel="noopener">demo ↗</a></div>
      <div class="vx-foot"><span class="vx-avatar"><?= htmlspecialchars($ini) ?></span><span><?= htmlspecialchars($name) ?></span><a href="index.php?logout=1">Odhlásit</a></div>
      </div>
    </aside>
    <script>
      // Mobil (≤900px): ☰ vysune menu zleva; zavře klik vedle / ✕ / Esc / výběr položky.
      function vxMenu(open) {
        var h = document.documentElement, on = typeof open === 'boolean' ? open : !h.classList.contains('vx-open');
        h.classList.toggle('vx-open', on);
        var b = document.querySelector('.vx-burger'); if (b) b.setAttribute('aria-expanded', on ? 'true' : 'false');
      }
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') vxMenu(false); });
      document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('.vx-nav a')) vxMenu(false); });
    </script>
    <?php
}
