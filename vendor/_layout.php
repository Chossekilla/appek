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

function vendor_nav_items(): array {
    // Flat list — backward compat. Pro 2-row layout viz vendor_nav_primary() + vendor_nav_secondary().
    return array_merge(vendor_nav_primary(), vendor_nav_secondary());
}

/**
 * 🆕 v2.0.98 — PRIMARY nav (daily use) — render velké buttony nahoře.
 */
function vendor_nav_primary(): array {
    return [
        ['key' => 'dashboard',     'label' => '📊 Přehled',      'url' => 'index.php'],
        ['key' => 'shop',          'label' => '🛒 Eshop',        'url' => 'shop.php'],
        ['key' => 'packages',      'label' => '🎁 Balíčky',      'url' => 'packages.php'],
        ['key' => 'licenses',      'label' => '🔑 Licence',      'url' => 'licenses.php'],
        ['key' => 'pages-editor',  'label' => '✏️ Web',           'url' => 'pages-editor.php'],
        ['key' => 'business-info', 'label' => '🏢 Firma',        'url' => 'business-info.php'],
        ['key' => 'updates',       'label' => '🔄 Updates',      'url' => 'updates.php'],
    ];
}

/**
 * 🆕 v2.0.98 — SECONDARY nav (admin / tools) — render malé obdélníky pod.
 */
function vendor_nav_secondary(): array {
    // 🆕 v2.5.2 — Pirate odstraněn ze secondary nav (interní audit nástroj,
    //            zůstává dostupný z dashboard hub karty + přes přímý URL).
    return [
        ['key' => 'self-update',   'label' => '🚀 Self-update',  'url' => 'self-update.php'],
        ['key' => 'access',        'label' => '👥 Přístupy',     'url' => 'access.php'],
        ['key' => 'demo',          'label' => '🧪 Demo',         'url' => 'demo-log.php'],
        ['key' => 'settings',      'label' => '⚙️ Nastavení',    'url' => 'settings.php'],
    ];
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
    <style>
      .footer-master {
        background: #fff; border-top: 1px solid #e5e5e7;
        margin-top: 40px; padding: 18px 0;
        color: #6e6e73; font-size: 12px;
      }
      .footer-inner {
        max-width: 1400px; margin: 0 auto; padding: 0 24px;
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap; gap: 10px;
      }
      .footer-master a {
        color: #6e6e73; text-decoration: none;
      }
      .footer-master a:hover { color: #BA7517; }
      .footer-master strong { color: #1d1d1f; font-weight: 700; }
      .footer-master .sep { margin: 0 6px; opacity: 0.4; }
      @media (max-width: 700px) {
        .footer-inner { flex-direction: column; align-items: flex-start; }
      }
    </style>
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
    <style>
      .back-master { max-width: 1400px; margin: 14px auto 0; padding: 0 24px; }
      .back-btn {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 7px 14px; border-radius: 8px;
        background: #fff; border: 1px solid #e5e5e7;
        color: #424245; text-decoration: none;
        font-size: 12.5px; font-weight: 600;
        transition: all 0.15s;
      }
      .back-btn:hover {
        background: #f5f5f7; border-color: #BA7517; color: #BA7517;
        transform: translateX(-2px);
      }
    </style>
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
    <style>
      .breadcrumbs-master {
        max-width: 1400px; margin: 12px auto 0; padding: 0 24px;
        font-size: 12.5px; color: #6e6e73;
      }
      .breadcrumbs-master a {
        color: #0058b8; text-decoration: none;
      }
      .breadcrumbs-master a:hover { text-decoration: underline; }
      .breadcrumbs-master .bc-sep { margin: 0 8px; color: #c7c7cc; }
      .breadcrumbs-master .bc-current { color: #1d1d1f; font-weight: 600; }
    </style>
    <?php
}

function vendor_render_topbar(array $user, string $currentPage = ''): void {
    // 🆕 v2.0.86 — Datum + svátek + verze pod logo (jako v admin)
    // 🆕 v2.9.61 — verze přes vendor_appek_version() (čte z api/config.php)
    $version = vendor_appek_version();
    $svatkyCZ = vendor_svatky_cz_map();
    $dny = ['neděle','pondělí','úterý','středa','čtvrtek','pátek','sobota'];
    $now = new DateTime('now');
    $den = $dny[(int)$now->format('w')];
    $datum = $now->format('j. n. Y');
    $klic = $now->format('j.n');
    $svatek = $svatkyCZ[$klic] ?? '';
    ?>
    <header class="topbar-master">
      <!-- ROW 1: Brand + meta + actions -->
      <div class="topbar-row topbar-row-top">
        <div class="brand-wrap-master">
          <a href="index.php" class="brand-master" style="text-decoration:none;color:inherit">
            🏢 <strong>APPEK</strong> <span>Master</span>
            <span class="brand-version-badge" title="Verze APPEK Master">v<?= htmlspecialchars($version) ?></span>
          </a>
          <div class="brand-meta-master" title="Datum · svátek">
            <span class="bm-date"><strong><?= htmlspecialchars($den) ?></strong> · <?= htmlspecialchars($datum) ?></span>
            <?php if ($svatek): ?>
              <span class="bm-sep">·</span>
              <span class="bm-svatek">🎉 svátek má <strong><?= htmlspecialchars($svatek) ?></strong></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="actions-master">
          <span class="user-chip-master">👤 <?= htmlspecialchars($user['display_name'] ?? $user['username']) ?></span>
          <a href="https://appek.cz/" target="_blank" rel="noopener" title="Otevřít appek.cz" style="font-size:16px;text-decoration:none">🌐</a>
          <a href="index.php?logout=1" class="logout-link">Odhlásit</a>
        </div>
      </div>

      <!-- ROW 2: Primary nav (velké obdélníky) -->
      <nav class="topbar-row topbar-row-primary">
        <?php foreach (vendor_nav_primary() as $item): ?>
          <a href="<?= htmlspecialchars($item['url']) ?>" class="nav-btn-primary <?= $currentPage === $item['key'] ? 'active' : '' ?>">
            <?= htmlspecialchars($item['label']) ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <!-- ROW 3: Secondary nav (malé obdélníky) -->
      <nav class="topbar-row topbar-row-secondary">
        <?php foreach (vendor_nav_secondary() as $item): ?>
          <a href="<?= htmlspecialchars($item['url']) ?>" class="nav-btn-secondary <?= $currentPage === $item['key'] ? 'active' : '' ?>">
            <?= htmlspecialchars($item['label']) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </header>
    <style>
      body { background: #f5f5f7; margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif; }

      /* ═══ v2.0.99 — APPLE LIGHT HUGE TOPBAR ═══════════════════════════
         Light frosted-glass · obrovské spacing · sladěné warm brand accents
         ═══════════════════════════════════════════════════════════════ */
      .topbar-master {
        position: sticky; top: 0; z-index: 100;
        background:
          linear-gradient(180deg, rgba(255, 255, 255, 0.94) 0%, rgba(252, 250, 247, 0.92) 100%);
        backdrop-filter: saturate(180%) blur(24px);
        -webkit-backdrop-filter: saturate(180%) blur(24px);
        color: #1d1d1f;
        border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        box-shadow:
          0 1px 0 rgba(0, 0, 0, 0.02),
          0 8px 24px rgba(0, 0, 0, 0.04);
      }
      .topbar-master::after {
        content: '';
        position: absolute; left: 0; right: 0; bottom: -1px;
        height: 1px;
        background: linear-gradient(90deg,
          transparent 0%,
          rgba(186, 117, 23, 0.18) 35%,
          rgba(186, 117, 23, 0.32) 50%,
          rgba(186, 117, 23, 0.18) 65%,
          transparent 100%);
        pointer-events: none;
      }
      /* 🆕 v2.0.99 — HUGE 3-row layout */
      .topbar-row {
        max-width: 1600px; margin: 0 auto;
        padding: 0 40px;
        display: flex; align-items: center; gap: 28px;
      }
      .topbar-row-top {
        padding-top: 22px; padding-bottom: 14px;
        justify-content: space-between;
        min-height: 76px;
      }
      .topbar-row-primary {
        padding-bottom: 10px;
        gap: 10px; flex-wrap: wrap;
      }
      .topbar-row-secondary {
        padding-bottom: 18px;
        gap: 8px; flex-wrap: wrap;
      }
      .brand-wrap-master {
        display: flex; flex-direction: column; gap: 6px;
        align-items: flex-start; min-width: 0; flex-shrink: 0;
      }
      .brand-master {
        font-size: 22px; letter-spacing: 0.3px;
        display: flex; align-items: center; gap: 12px;
        white-space: nowrap;
        font-feature-settings: "ss01";
      }
      .brand-master strong {
        font-weight: 800;
        background: linear-gradient(135deg, #D89940 0%, #BA7517 50%, #854F0B 100%);
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
        letter-spacing: -0.015em;
      }
      /* 🆕 v2.6.3 — small version badge vedle "APPEK Master" */
      .brand-version-badge {
        font-size: 10.5px !important;
        font-weight: 600 !important;
        letter-spacing: 0.3px !important;
        text-transform: none !important;
        background: linear-gradient(135deg, rgba(186, 117, 23, 0.12), rgba(186, 117, 23, 0.06)) !important;
        color: #854F0B !important;
        padding: 2px 8px !important;
        border-radius: 999px !important;
        border: 1px solid rgba(186, 117, 23, 0.18) !important;
        margin-left: 4px !important;
        font-family: 'SF Mono', Menlo, Consolas, monospace !important;
        opacity: 1 !important;
        white-space: nowrap !important;
        vertical-align: baseline !important;
      }
      .brand-master span:not(.brand-version-badge) {
        font-size: 13px; opacity: 0.5; letter-spacing: 3px;
        text-transform: uppercase; font-weight: 600;
        color: #6e6e73;
      }

      /* Brand meta — apple light style */
      .brand-meta-master {
        display: flex; flex-wrap: nowrap; align-items: center; gap: 10px;
        font-size: 13.5px; line-height: 1.3;
        color: rgba(0, 0, 0, 0.5);
        font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif;
        padding-left: 36px;
        font-variant-numeric: tabular-nums;
        letter-spacing: 0.1px;
      }
      .brand-meta-master .bm-date strong {
        color: #1d1d1f;
        font-weight: 700;
      }
      .brand-meta-master .bm-svatek {
        color: rgba(0, 0, 0, 0.55);
      }
      .brand-meta-master .bm-svatek strong {
        color: #BA7517;
        font-weight: 700;
      }
      .brand-meta-master .bm-ver {
        background: linear-gradient(135deg, #FFF8E7 0%, #FAEEDA 100%);
        color: #854F0B;
        padding: 4px 12px;
        border-radius: 999px;
        font-weight: 700;
        font-size: 12px;
        letter-spacing: 0.3px;
        border: 1px solid rgba(186, 117, 23, 0.25);
        box-shadow: 0 1px 3px rgba(186, 117, 23, 0.08);
      }
      .brand-meta-master .bm-sep { opacity: 0.3; font-weight: 400; color: rgba(0, 0, 0, 0.4); }
      @media (max-width: 900px) {
        .brand-meta-master { font-size: 12px; padding-left: 0; gap: 6px; }
      }
      @media (max-width: 480px) {
        .brand-meta-master .bm-sep:nth-of-type(1) { display: none; }
        .brand-meta-master .bm-date { display: none; }
      }

      /* === 🆕 v2.0.99 — PRIMARY NAV (HUGE light buttons, low round corner) === */
      .nav-btn-primary {
        color: #1d1d1f;
        text-decoration: none;
        padding: 16px 28px;
        border-radius: 12px;
        font-size: 16px;
        font-weight: 600;
        letter-spacing: -0.005em;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        white-space: nowrap;
        background: rgba(0, 0, 0, 0.03);
        border: 1px solid rgba(0, 0, 0, 0.05);
        display: inline-flex;
        align-items: center;
        line-height: 1.2;
        flex: 0 0 auto;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
      }
      .nav-btn-primary:hover {
        background: rgba(186, 117, 23, 0.08);
        color: #854F0B;
        border-color: rgba(186, 117, 23, 0.2);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(186, 117, 23, 0.12);
      }
      .nav-btn-primary.active {
        background: linear-gradient(135deg, #BA7517 0%, #854F0B 100%);
        color: #fff;
        border-color: rgba(186, 117, 23, 0.6);
        box-shadow:
          inset 0 1px 0 rgba(255, 255, 255, 0.2),
          0 4px 14px rgba(186, 117, 23, 0.3),
          0 0 0 1px rgba(186, 117, 23, 0.2);
      }

      /* === 🆕 v2.0.99 — SECONDARY NAV (small light rectangles pod primary) === */
      .nav-btn-secondary {
        color: rgba(0, 0, 0, 0.55);
        text-decoration: none;
        padding: 9px 16px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 500;
        letter-spacing: 0.1px;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        white-space: nowrap;
        background: transparent;
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        line-height: 1.2;
        flex: 0 0 auto;
      }
      .nav-btn-secondary:hover {
        background: rgba(0, 0, 0, 0.04);
        color: #1d1d1f;
        border-color: rgba(0, 0, 0, 0.06);
      }
      .nav-btn-secondary.active {
        background: rgba(186, 117, 23, 0.1);
        color: #854F0B;
        border-color: rgba(186, 117, 23, 0.25);
        font-weight: 600;
      }

      /* === ACTIONS — light Apple-style chip + ghost buttons === */
      .actions-master {
        display: flex; align-items: center; gap: 12px;
        font-size: 14px;
        flex-shrink: 0;
      }
      .actions-master a {
        color: rgba(0, 0, 0, 0.65);
        text-decoration: none;
        padding: 10px 16px;
        border-radius: 10px;
        font-weight: 500;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      }
      .actions-master a:hover {
        background: rgba(0, 0, 0, 0.05);
        color: #1d1d1f;
      }
      .actions-master a[href*="logout"]:hover,
      .actions-master a.logout-link:hover {
        background: rgba(220, 38, 38, 0.08);
        color: #991B1B;
      }
      .user-chip-master {
        display: inline-flex; align-items: center; gap: 9px;
        background: linear-gradient(135deg, #FFF8E7 0%, #FAEEDA 100%);
        border: 1px solid rgba(186, 117, 23, 0.22);
        padding: 10px 18px;
        border-radius: 999px;
        font-size: 14px;
        font-weight: 600;
        color: #5C3608;
        max-width: 280px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        box-shadow:
          0 1px 0 rgba(255, 255, 255, 0.6) inset,
          0 1px 3px rgba(186, 117, 23, 0.08);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      }
      .user-chip-master:hover {
        background: linear-gradient(135deg, #FFFCF5 0%, #FAEEDA 100%);
        border-color: rgba(186, 117, 23, 0.35);
        transform: translateY(-1px);
        box-shadow:
          0 1px 0 rgba(255, 255, 255, 0.7) inset,
          0 4px 12px rgba(186, 117, 23, 0.15);
      }
      .page-master {
        max-width: 1400px; margin: 0 auto; padding: 24px;
      }
      .page-header-master {
        background: #fff; border-radius: 12px; padding: 20px 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        margin-bottom: 20px;
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 12px;
      }
      .page-header-master h1 { margin: 0; font-size: 22px; }
      .panel-master {
        background: #fff; border-radius: 12px;
        padding: 24px; margin-bottom: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      }
      .panel-master h2 { font-size: 16px; margin: 0 0 14px; color: #1d1d1f; }
      .btn-master {
        padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer;
        font-family: inherit; font-size: 13.5px; font-weight: 600;
        text-decoration: none; display: inline-block;
        transition: all 0.15s;
      }
      .btn-master.primary {
        background: linear-gradient(180deg, #BA7517, #854F0B); color: #fff;
      }
      .btn-master.primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(186,117,23,0.3); }
      .btn-master.secondary { background: #f5f5f7; color: #1d1d1f; }
      /* 🆕 v2.0.99 — Responzivita HUGE 2-row nav */
      @media (max-width: 1280px) {
        .topbar-row { padding-left: 28px; padding-right: 28px; }
        .nav-btn-primary { padding: 14px 22px; font-size: 15px; }
        .brand-master { font-size: 20px; }
      }
      @media (max-width: 1024px) {
        .topbar-row { padding-left: 20px; padding-right: 20px; gap: 16px; }
        .topbar-row-top { padding-top: 16px; padding-bottom: 10px; min-height: 64px; }
        .nav-btn-primary { padding: 12px 18px; font-size: 14px; }
        .nav-btn-secondary { padding: 7px 12px; font-size: 12px; }
        .brand-master { font-size: 18px; }
        .brand-meta-master { padding-left: 30px; font-size: 12.5px; }
      }
      @media (max-width: 900px) {
        .topbar-row-top { flex-wrap: wrap; gap: 12px; }
        /* 🆕 v2.5.2 — natural WRAP místo horizontal scroll (user feedback) */
        .topbar-row-primary, .topbar-row-secondary {
          flex-wrap: wrap;
          row-gap: 8px;
          padding-left: 16px; padding-right: 16px;
        }
        .nav-btn-primary { padding: 10px 14px; font-size: 13px; }
        .nav-btn-secondary { padding: 7px 12px; font-size: 12.5px; }
        .user-chip-master { padding: 8px 14px; font-size: 13px; }
      }
      @media (max-width: 600px) {
        .topbar-row { padding-left: 12px; padding-right: 12px; }
        .topbar-row-top { padding-top: 12px; padding-bottom: 8px; gap: 8px; min-height: 0; }
        .topbar-row-primary, .topbar-row-secondary { row-gap: 6px; }
        .nav-btn-primary { padding: 9px 12px; font-size: 12.5px; }
        .nav-btn-secondary { padding: 6px 10px; font-size: 12px; }
        .brand-master { font-size: 16px; gap: 8px; }
        .brand-master span { display: none; }
        .actions-master { gap: 6px; }
        .actions-master a { padding: 8px 12px; font-size: 13px; }
      }
    </style>
    <?php
}
