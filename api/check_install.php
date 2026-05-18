<?php
/**
 * APPEK B2B - kontrola instalace
 *
 * Tento skript ověří, že všechny soubory jsou nahrané a DB má všechny tabulky.
 * Použití:
 *   https://váš-web/api/check_install.php?key=appekSet2026
 *
 * Po úspěšné kontrole soubor SMAŽTE z hostingu.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

if (($_GET['key'] ?? '') !== 'appekSet2026') {
    http_response_code(403);
    die('<h1>403</h1><p>Chybí klíč: ?key=appekSet2026</p>');
}

echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Kontrola instalace</title>';
echo '<style>
body{font-family:-apple-system,sans-serif;max-width:900px;margin:30px auto;padding:20px;color:#2C2C2A;background:#F7F5F0}
.card{background:#fff;padding:24px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:16px}
.ok{color:#27500A;background:#EAF3DE;padding:10px 14px;border-radius:6px;margin:4px 0}
.fail{color:#A32D2D;background:#FCEBEB;padding:10px 14px;border-radius:6px;margin:4px 0;font-weight:500}
.warn{color:#854F0B;background:#FAEEDA;padding:10px 14px;border-radius:6px;margin:4px 0}
h1{color:#BA7517;margin-bottom:8px}
h2{margin-top:24px;font-size:18px;border-bottom:2px solid #FAEEDA;padding-bottom:6px}
code{background:#F1EFE8;padding:2px 6px;border-radius:4px;font-size:13px}
.summary{font-size:18px;padding:16px;border-radius:8px;margin-top:24px}
.summary.ok{background:#27500A;color:white}
.summary.fail{background:#A32D2D;color:white}
</style></head><body><div class="card"><h1>🔍 Kontrola instalace APPEK B2B</h1>';

$errors = 0;
$warnings = 0;

// =============================================================
// 1. Kontrola souborů na disku
// =============================================================
echo '<h2>1. Soubory v api/</h2>';

$expected_files = [
    '_admin_auth.php',
    'admin_cenove_skupiny.php',
    'admin_dashboard.php',
    'admin_faktury.php',
    'admin_login.php',
    'admin_nastaveni.php',
    'admin_objednavky.php',
    'admin_odberatele.php',
    'admin_pobocky.php',
    'admin_users.php',
    'admin_vyroba.php',
    'admin_vyrobky.php',
    'cenik_odberatele.php',
    'config.php',
    'dodaci_list.php',
    'faktura.php',
    'katalog.php',
    'login.php',
    'logout.php',
    'mista_dodani.php',
    'objednavky.php',
    'statistiky.php',
    'vyrobni_list_print.php',
    'whoami.php',
];

$dir = __DIR__;
foreach ($expected_files as $f) {
    if (file_exists("$dir/$f")) {
        echo "<div class='ok'>✓ $f</div>";
    } else {
        echo "<div class='fail'>❌ CHYBÍ: $f</div>";
        $errors++;
    }
}

// =============================================================
// 2. Verze admin.js (musí mít 'cenove_skupiny')
// =============================================================
echo '<h2>2. Verze admin.js</h2>';

$admin_js = realpath("$dir/../admin/admin.js");
if (!$admin_js || !file_exists($admin_js)) {
    echo "<div class='fail'>❌ admin.js nenalezen</div>";
    $errors++;
} else {
    $size = filesize($admin_js);
    $content = file_get_contents($admin_js);
    $has_cenove = strpos($content, 'renderCenoveSkupiny') !== false;
    $has_rucni = strpos($content, 'otevritRucniFakturu') !== false;

    if ($has_cenove && $has_rucni) {
        echo "<div class='ok'>✓ admin.js obsahuje renderCenoveSkupiny i otevritRucniFakturu (velikost: " . round($size / 1024) . " KB)</div>";
    } else {
        echo "<div class='fail'>❌ admin.js je STARÁ VERZE (velikost: " . round($size / 1024) . " KB) — neobsahuje funkce pro slevové skupiny / ruční faktury. Nahrajte čerstvý admin.js z balíčku!</div>";
        $errors++;
    }
}

// =============================================================
// 3. Cache buster v HTML
// =============================================================
echo '<h2>3. Cache buster v HTML</h2>';

$admin_html = realpath("$dir/../admin/index.html");
if ($admin_html) {
    $html = file_get_contents($admin_html);
    if (preg_match('/v=([\d.]+)/', $html, $m)) {
        echo "<div class='ok'>✓ admin/index.html má cache buster v=" . htmlspecialchars($m[1]) . "</div>";
        if (version_compare($m[1], '3.4', '<')) {
            echo "<div class='warn'>⚠️ Verze " . htmlspecialchars($m[1]) . " je starší než 3.4. Nahrajte čerstvý index.html.</div>";
            $warnings++;
        }
    } else {
        echo "<div class='warn'>⚠️ index.html nemá cache buster ?v=...</div>";
        $warnings++;
    }
}

// =============================================================
// 4. Připojení k DB a kontrola tabulek
// =============================================================
echo '<h2>4. Databáze</h2>';

require_once __DIR__ . '/config.php';

try {
    $pdo = db();
    echo "<div class='ok'>✓ Připojení k DB funguje</div>";

    $expected_tables = [
        'admin_users', 'cenove_skupiny', 'cenove_skupiny_slevy',
        'cislovani', 'dodaci_list_polozky', 'dodaci_listy',
        'faktura_polozky', 'faktury', 'faktury_dodaci_listy',
        'jednotky', 'kategorie_vyrobku', 'mista_dodani',
        'nastaveni', 'objednavky', 'objednavky_polozky',
        'odberatele', 'prihlaseni_pokusy', 'sazby_dph',
        'vyrobky', 'vyrobni_list_polozky', 'vyrobni_listy',
    ];

    $stmt = $pdo->query("SHOW TABLES");
    $existing = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $existing[] = $row[0];
    }

    foreach ($expected_tables as $t) {
        if (in_array($t, $existing, true)) {
            $cnt = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            echo "<div class='ok'>✓ Tabulka <code>$t</code> existuje ($cnt záznamů)</div>";
        } else {
            echo "<div class='fail'>❌ CHYBÍ tabulka: <code>$t</code></div>";
            $errors++;
        }
    }

    // Specifická kontrola: má odberatele.cenova_skupina_id?
    $stmt = $pdo->query("SHOW COLUMNS FROM odberatele LIKE 'cenova_skupina_id'");
    if ($stmt->fetchColumn()) {
        echo "<div class='ok'>✓ <code>odberatele.cenova_skupina_id</code> existuje</div>";
    } else {
        echo "<div class='fail'>❌ Sloupec <code>odberatele.cenova_skupina_id</code> CHYBÍ. Spusťte migrace_02_cenove_skupiny.sql v phpMyAdmin.</div>";
        $errors++;
    }

    // Faktury.rucni?
    $stmt = $pdo->query("SHOW COLUMNS FROM faktury LIKE 'rucni'");
    if ($stmt->fetchColumn()) {
        echo "<div class='ok'>✓ <code>faktury.rucni</code> existuje</div>";
    } else {
        echo "<div class='fail'>❌ Sloupec <code>faktury.rucni</code> CHYBÍ. Spusťte migrace_02_cenove_skupiny.sql.</div>";
        $errors++;
    }

    // Kategorie.obrazek_url?
    $stmt = $pdo->query("SHOW COLUMNS FROM kategorie_vyrobku LIKE 'obrazek_url'");
    if ($stmt->fetchColumn()) {
        echo "<div class='ok'>✓ <code>kategorie_vyrobku.obrazek_url</code> existuje</div>";
    } else {
        echo "<div class='fail'>❌ Sloupec <code>kategorie_vyrobku.obrazek_url</code> CHYBÍ. Spusťte migrace_03_sortiment_obrazky.sql v phpMyAdmin.</div>";
        $errors++;
    }

    // Admin existuje?
    $cnt = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE aktivni = 1")->fetchColumn();
    if ($cnt > 0) {
        echo "<div class='ok'>✓ V DB je $cnt aktivních admin uživatelů</div>";
    } else {
        echo "<div class='fail'>❌ V admin_users není žádný aktivní uživatel — nemůžete se přihlásit!</div>";
        $errors++;
    }

} catch (Exception $e) {
    echo "<div class='fail'>❌ Chyba DB: " . htmlspecialchars($e->getMessage()) . "</div>";
    $errors++;
}

// =============================================================
// SOUHRN
// =============================================================
if ($errors === 0 && $warnings === 0) {
    echo '<div class="summary ok">✅ VŠE OK — instalace je kompletní!</div>';
    echo '<p style="margin-top:16px">Můžete se přihlásit do adminu a používat všechny funkce.</p>';
    echo '<div class="warn">⚠️ <strong>Smažte tento soubor</strong> z hostingu (api/check_install.php).</div>';
} elseif ($errors > 0) {
    echo "<div class='summary fail'>❌ Nalezeno $errors chyb a $warnings varování</div>";
    echo '<p style="margin-top:16px">Opravte chyby výše a spusťte tento skript znovu.</p>';
} else {
    echo "<div class='summary' style='background:#FAEEDA;color:#854F0B'>⚠️ $warnings varování — funkční, ale doporučujeme dořešit</div>";
}

echo '</div></body></html>';
