<?php
/**
 * Záloha — pure functions (bez routeru).
 * Include v admin_zalohy.php (hlavní endpoint) i v ostatních destruktivních endpointech.
 *
 * Hlavní use case:
 *   require_once __DIR__ . '/_zaloha_helper.php';
 *   zaloha_setup($pdo);
 *   zaloha_snapshot($pdo, 'Před přečíslováním výrobků');
 *
 * Snapshot je rychlá záloha DB (bez uploads), označená type='snapshot'.
 * Pokud selže, logujeme, ale **NEHODÍME výjimku** — chceme aby destruktivní akce
 * pokračovala (radši ztratit pojistku než blokovat uživatele kvůli backupu).
 */

// =============================================================
// SELF-HEAL MIGRACE — doplní chybějící sloupce do existující tabulky zalohy
// 🐞 v2.9.111 FIX: `CREATE TABLE IF NOT EXISTS` NEALTERuje už existující
// tabulku. Instalace se starým `zalohy` (bez include_uploads / vytvoril …)
// → INSERT/SELECT spadl na „Unknown column 'include_uploads'". Tady chybějící
// sloupce dodatečně přidáme přes ALTER TABLE.
// =============================================================
if (!function_exists('zaloha_migrate_columns')) {
function zaloha_migrate_columns(PDO $pdo): void {
    try {
        $have = $pdo->query("SHOW COLUMNS FROM zalohy")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return; // tabulka ještě neexistuje — CREATE ji založí kompletní
    }
    $want = [
        'soubor'          => "VARCHAR(255) DEFAULT NULL",
        'typ'             => "VARCHAR(30) DEFAULT 'manual'",
        'label'           => "VARCHAR(500) DEFAULT NULL",
        'velikost'        => "BIGINT DEFAULT 0",
        'tabulek'         => "INT DEFAULT 0",
        'zaznamu'         => "BIGINT DEFAULT 0",
        'include_uploads' => "TINYINT(1) DEFAULT 0",
        'vytvoril'        => "VARCHAR(100) DEFAULT NULL",
        'vytvoreno'       => "DATETIME DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($want as $col => $def) {
        if (!in_array($col, $have, true)) {
            try { $pdo->exec("ALTER TABLE zalohy ADD COLUMN `$col` $def"); }
            catch (Throwable $e) { error_log('zaloha_migrate_columns ' . $col . ': ' . $e->getMessage()); }
        }
    }
    // 🐛 v3.0.283 — prastará tabulka má legacy `nazev_souboru` NOT NULL bez defaultu,
    // dnešní INSERT ho neplní → 1364 "Field doesn't have a default value". Uvolni default.
    if (in_array('nazev_souboru', $have, true)) {
        try { $pdo->exec("ALTER TABLE zalohy MODIFY nazev_souboru VARCHAR(255) NOT NULL DEFAULT ''"); }
        catch (Throwable $e) { error_log('zaloha_migrate_columns nazev_souboru: ' . $e->getMessage()); }
    }
}
}

// =============================================================
// SETUP — tabulka a složka
// =============================================================
if (!function_exists('zaloha_setup')) {
function zaloha_setup(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS zalohy (
            id INT AUTO_INCREMENT PRIMARY KEY,
            soubor VARCHAR(255) NOT NULL,
            typ VARCHAR(20) NOT NULL DEFAULT 'manual',
            label VARCHAR(255) DEFAULT NULL,
            velikost BIGINT DEFAULT 0,
            tabulek INT DEFAULT 0,
            zaznamu INT DEFAULT 0,
            include_uploads TINYINT(1) DEFAULT 0,
            vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
            vytvoril VARCHAR(100) DEFAULT NULL,
            INDEX idx_vytvoreno (vytvoreno),
            INDEX idx_typ (typ)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    zaloha_migrate_columns($pdo);   // 🆕 v2.9.111 — doplň chybějící sloupce do staré tabulky
    $dir = __DIR__ . '/../zalohy';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Order allow,deny\nDeny from all\n");
}
}

// =============================================================
// DUMP DB
// =============================================================
if (!function_exists('zaloha_dump_db')) {
function zaloha_dump_db(PDO $pdo): array {
    $tabulky = $pdo->query("
        SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
    ")->fetchAll(PDO::FETCH_COLUMN);

    $sql = "-- ============================================================\n";
    $sql .= "-- Záloha databáze APPEK B2B\n";
    $sql .= "-- Vytvořeno: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- PHP: " . PHP_VERSION . "\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $sql .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

    $celkem_zaznamu = 0;
    foreach ($tabulky as $t) {
        $sql .= "-- ============================================================\n";
        $sql .= "-- Tabulka: `$t`\n";
        $sql .= "-- ============================================================\n";
        $sql .= "DROP TABLE IF EXISTS `$t`;\n";
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        $sql .= $create[1] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_NUM);
        if (!empty($rows)) {
            $columns_info = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
            $col_names = array_map(fn($c) => '`' . $c['Field'] . '`', $columns_info);

            $sql .= "INSERT INTO `$t` (" . implode(', ', $col_names) . ") VALUES\n";
            $chunks = [];
            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) $vals[] = 'NULL';
                    elseif (is_int($v) || is_float($v)) $vals[] = $v;
                    else $vals[] = $pdo->quote($v);
                }
                $chunks[] = '(' . implode(', ', $vals) . ')';
            }
            $batches = array_chunk($chunks, 100);
            $first = true;
            foreach ($batches as $i => $batch) {
                if (!$first) $sql .= ";\nINSERT INTO `$t` (" . implode(', ', $col_names) . ") VALUES\n";
                $sql .= implode(",\n", $batch);
                $first = false;
            }
            $sql .= ";\n\n";
            $celkem_zaznamu += count($rows);
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $sql .= "-- Konec zálohy --\n";

    return [
        'sql'      => $sql,
        'tabulek'  => count($tabulky),
        'zaznamu'  => $celkem_zaznamu,
    ];
}
}

// =============================================================
// PŘIDEJ /uploads DO ZIPU
// =============================================================
if (!function_exists('zaloha_pridej_uploads')) {
function zaloha_pridej_uploads(ZipArchive $zip, string $base_path): int {
    $upload_dir = $base_path . '/uploads';
    if (!is_dir($upload_dir)) return 0;
    $pocet = 0;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($upload_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iter as $f) {
        if (!$f->isFile()) continue;
        $rel = 'uploads/' . substr($f->getPathname(), strlen($upload_dir) + 1);
        $zip->addFile($f->getPathname(), $rel);
        $pocet++;
    }
    return $pocet;
}
}

// =============================================================
// VYTVOŘ ZÁLOHU
// =============================================================
if (!function_exists('zaloha_vytvor')) {
function zaloha_vytvor(PDO $pdo, array $opts): array {
    $typ              = $opts['typ'] ?? 'manual';
    $label            = $opts['label'] ?? null;
    $include_uploads  = !empty($opts['include_uploads']);
    $vytvoril         = $opts['vytvoril'] ?? null;

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive není dostupné na tomto hostingu — kontaktuj support.');
    }

    // 🆕 v2.0.67 — Self-heal: auto-create zalohy table pokud chybí
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS zalohy (
                id INT AUTO_INCREMENT PRIMARY KEY,
                soubor VARCHAR(255) NOT NULL,
                typ VARCHAR(30) DEFAULT 'manual',
                label VARCHAR(500),
                velikost BIGINT DEFAULT 0,
                tabulek INT DEFAULT 0,
                zaznamu BIGINT DEFAULT 0,
                include_uploads TINYINT(1) DEFAULT 0,
                vytvoril VARCHAR(100),
                vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_typ (typ),
                INDEX idx_vytvoreno (vytvoreno)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        zaloha_migrate_columns($pdo);   // 🆕 v2.9.111 — CREATE IF NOT EXISTS staré tabulky nealteruje → dodělej sloupce
    } catch (Throwable $e) {
        throw new RuntimeException('Nelze ověřit/vytvořit tabulku zalohy: ' . $e->getMessage());
    }

    // 🆕 v2.0.67 — Self-heal: vytvoř zalohy/ složku pokud chybí + ověř write permissions
    $dir = __DIR__ . '/../zalohy';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(
                "Nelze vytvořit složku zalohy/ na: $dir\n" .
                "Web server pravděpodobně nemá zápisová práva. Vytvoř ji ručně přes FTP/FileManager + chmod 755."
            );
        }
    }
    if (!is_writable($dir)) {
        throw new RuntimeException(
            "Složka zalohy/ existuje, ale není zapisovatelná.\n" .
            "Fix: chmod 755 na zalohy/ (FTP nebo SSH: chmod 755 zalohy)"
        );
    }

    $stamp = date('Ymd_His');
    $name  = 'zaloha_' . $stamp . '_' . $typ . '.zip';
    $path  = $dir . '/' . $name;

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Nelze vytvořit ZIP soubor na: $path");
    }

    try {
        $dump = zaloha_dump_db($pdo);
    } catch (Throwable $e) {
        $zip->close();
        @unlink($path);
        throw new RuntimeException('SQL dump selhal: ' . $e->getMessage());
    }
    $zip->addFromString('db.sql', $dump['sql']);

    $upload_count = 0;
    if ($include_uploads) {
        try {
            $upload_count = zaloha_pridej_uploads($zip, dirname(__DIR__));
        } catch (Throwable $e) {
            error_log('zaloha uploads: ' . $e->getMessage());
            // pokračujeme — uploads jsou volitelné
        }
    }

    $meta = [
        'verze'           => '1',
        'vytvoreno'       => date('c'),
        'typ'             => $typ,
        'label'           => $label,
        'vytvoril'        => $vytvoril,
        'tabulek'         => $dump['tabulek'],
        'zaznamu'         => $dump['zaznamu'],
        'include_uploads' => $include_uploads,
        'pocet_uploads'   => $upload_count,
        'php'             => PHP_VERSION,
    ];
    $zip->addFromString('metadata.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->close();

    $velikost = filesize($path) ?: 0;

    $stmt = $pdo->prepare("
        INSERT INTO zalohy (soubor, typ, label, velikost, tabulek, zaznamu, include_uploads, vytvoril)
        VALUES (:s, :t, :l, :v, :tb, :z, :iu, :vu)
    ");
    $stmt->execute([
        's'  => $name, 't' => $typ, 'l' => $label, 'v' => $velikost,
        'tb' => $dump['tabulek'], 'z' => $dump['zaznamu'],
        'iu' => $include_uploads ? 1 : 0, 'vu' => $vytvoril,
    ]);
    $id = (int) $pdo->lastInsertId();

    return [
        'id'        => $id,
        'soubor'    => $name,
        'velikost'  => $velikost,
        'tabulek'   => $dump['tabulek'],
        'zaznamu'   => $dump['zaznamu'],
        'uploads'   => $upload_count,
    ];
}
}

// =============================================================
// ROTACE
// =============================================================
if (!function_exists('zaloha_rotace')) {
function zaloha_rotace(PDO $pdo): int {
    $keep_daily   = 30;
    $keep_monthly = 12;
    $keep_snapshot = 50;
    $smazano = 0;

    foreach (['auto', 'manual'] as $t) {
        $vsechny = $pdo->prepare("SELECT id, soubor, vytvoreno FROM zalohy WHERE typ = :t ORDER BY vytvoreno DESC");
        $vsechny->execute(['t' => $t]);
        $vsechny = $vsechny->fetchAll();

        $kept_per_month = [];
        $i = 0;
        foreach ($vsechny as $z) {
            $month = substr($z['vytvoreno'], 0, 7);
            if ($i < $keep_daily) {
                // ponech
            } elseif (!isset($kept_per_month[$month]) && count($kept_per_month) < $keep_monthly) {
                $kept_per_month[$month] = true;
            } else {
                $f = __DIR__ . '/../zalohy/' . $z['soubor'];
                if (file_exists($f)) @unlink($f);
                $pdo->prepare("DELETE FROM zalohy WHERE id = :id")->execute(['id' => $z['id']]);
                $smazano++;
            }
            $i++;
        }
    }

    $snap = $pdo->prepare("SELECT id, soubor FROM zalohy WHERE typ = 'snapshot' ORDER BY vytvoreno DESC LIMIT 9999 OFFSET :keep");
    $snap->bindValue(':keep', $keep_snapshot, PDO::PARAM_INT);
    $snap->execute();
    foreach ($snap->fetchAll() as $z) {
        $f = __DIR__ . '/../zalohy/' . $z['soubor'];
        if (file_exists($f)) @unlink($f);
        $pdo->prepare("DELETE FROM zalohy WHERE id = :id")->execute(['id' => $z['id']]);
        $smazano++;
    }

    return $smazano;
}
}

// =============================================================
// OBNOVA
// =============================================================
if (!function_exists('zaloha_obnov')) {
function zaloha_obnov(PDO $pdo, int $id): array {
    if (function_exists('require_super_admin')) require_super_admin();
    $z = $pdo->prepare("SELECT * FROM zalohy WHERE id = :id");
    $z->execute(['id' => $id]);
    $z = $z->fetch();
    if (!$z) throw new RuntimeException('Záloha nenalezena');
    $path = __DIR__ . '/../zalohy/' . $z['soubor'];
    if (!file_exists($path)) throw new RuntimeException('Soubor zálohy neexistuje na disku');

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Nelze otevřít ZIP');
    $sql = $zip->getFromName('db.sql');
    $zip->close();
    if ($sql === false || $sql === '') throw new RuntimeException('ZIP neobsahuje db.sql');

    try {
        zaloha_vytvor($pdo, [
            'typ'   => 'snapshot',
            'label' => 'Pre-restore snapshot (před obnovou ID ' . $id . ')',
        ]);
    } catch (Throwable $e) {
        error_log('Pre-restore snapshot selhal: ' . $e->getMessage());
    }

    $stmts = preg_split("/;\s*\n/", $sql);
    $pocet_ok = 0; $pocet_err = 0; $errors = [];
    foreach ($stmts as $s) {
        $s = trim($s);
        if ($s === '' || strpos($s, '--') === 0) continue;
        try {
            $pdo->exec($s);
            $pocet_ok++;
        } catch (Throwable $e) {
            $pocet_err++;
            if (count($errors) < 5) $errors[] = $e->getMessage();
        }
    }
    return ['ok' => $pocet_err === 0, 'provedeno' => $pocet_ok, 'chyb' => $pocet_err, 'errors' => $errors];
}
}

// =============================================================
// SNAPSHOT — convenience wrapper pro destruktivní endpointy
// =============================================================
if (!function_exists('zaloha_snapshot')) {
/**
 * Vytvoří rychlý snapshot DB. Volá se před destruktivními akcemi.
 *
 * SELHÁNÍ NEHODÍ VÝJIMKU — logujeme a vrátíme null, aby destruktivní akce mohla
 * pokračovat. Backup je pojistka, ne blocker. Pokud se snapshot nepovede, raději
 * ho oželíme, než aby uživatel nemohl pracovat.
 *
 * @return array|null Pole s info o vytvořené záloze, nebo null při selhání.
 */
function zaloha_snapshot(PDO $pdo, string $label): ?array {
    try {
        // Zajisti, že tabulka a složka existují (idempotentní)
        zaloha_setup($pdo);

        $admin_jmeno = $_SESSION['admin_jmeno'] ?? 'system';
        $result = zaloha_vytvor($pdo, [
            'typ'      => 'snapshot',
            'label'    => $label,
            'vytvoril' => $admin_jmeno,
            'include_uploads' => false, // snapshoty jsou jen DB, ať jsou rychlé
        ]);
        // Po snapshotu rotace — uklid staré snapshoty
        @zaloha_rotace($pdo);
        return $result;
    } catch (Throwable $e) {
        error_log('zaloha_snapshot selhal (label: ' . $label . '): ' . $e->getMessage());
        return null;
    }
}
}
