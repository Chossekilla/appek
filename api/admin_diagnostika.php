<?php
/**
 * Diagnostická stránka administrace.
 * Vrací JSON se všemi důležitými informacemi o systému, DB, schématu, chybách atd.
 *
 * GET ?action=info        — vše najednou (default)
 * GET ?action=ping_mail   — pošle testovací mail na adresu z firma_email
 * GET ?action=lint        — projede všechny api/*.php přes token_get_all (zachytí parse errors)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
$action = $_GET['action'] ?? 'info';

// ===================================================================
// Pomocné funkce
// ===================================================================
function fmt_bytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' kB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / 1024 / 1024, 1) . ' MB';
    return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
}

function dir_size_recursive(string $dir): array {
    if (!is_dir($dir)) return ['size' => 0, 'files' => 0];
    $size = 0; $files = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        if ($f->isFile()) { $size += $f->getSize(); $files++; }
    }
    return ['size' => $size, 'files' => $files];
}

// ===================================================================
// ACTION: ping_mail — test odeslání mailu
// ===================================================================
if ($action === 'ping_mail') {
    $to = trim($_GET['to'] ?? '');
    if (!$to) $to = nastaveni_get($pdo, 'firma_email', '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) json_error('Neplatný email');

    $firma = nastaveni_get($pdo, 'firma_nazev', 'Provoz');
    $from = nastaveni_get($pdo, 'firma_email', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $subj = '=?UTF-8?B?' . base64_encode("[DIAG] Test e-mailu — $firma") . '?=';
    $body = "Diagnostický test\n\nČas: " . date('Y-m-d H:i:s') . "\nServer: " . ($_SERVER['HTTP_HOST'] ?? '?') . "\n";
    $headers = "From: $firma <$from>\r\nReply-To: $from\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $ok = @mail($to, $subj, $body, $headers);
    json_response(['ok' => $ok, 'to' => $to, 'from' => $from]);
}

// ===================================================================
// ACTION: lint — projeď všechny PHP soubory v api/ a hledej parse chyby
// ===================================================================
if ($action === 'lint') {
    $dir = __DIR__;
    $files = glob($dir . '/*.php');
    $results = [];
    foreach ($files as $f) {
        $code = @file_get_contents($f);
        $name = basename($f);
        if ($code === false) {
            $results[] = ['file' => $name, 'status' => 'unreadable'];
            continue;
        }
        try {
            $tokens = @token_get_all($code, TOKEN_PARSE);
            $results[] = [
                'file' => $name,
                'status' => is_array($tokens) ? 'ok' : 'fail',
                'size' => strlen($code),
                'lines' => substr_count($code, "\n") + 1,
            ];
        } catch (Throwable $e) {
            $results[] = [
                'file' => $name,
                'status' => 'parse_error',
                'msg'  => $e->getMessage(),
            ];
        }
    }
    json_response(['files' => $results]);
}

// ===================================================================
// ACTION: info — default, vše ostatní
// ===================================================================

// --- SYSTEM ---
$system = [
    'php_version'         => PHP_VERSION,
    'php_sapi'            => PHP_SAPI,
    'os'                  => php_uname('s') . ' ' . php_uname('r'),
    'server_software'     => $_SERVER['SERVER_SOFTWARE'] ?? '?',
    'host'                => $_SERVER['HTTP_HOST'] ?? '?',
    'document_root'       => $_SERVER['DOCUMENT_ROOT'] ?? '?',
    'server_time'         => date('Y-m-d H:i:s'),
    'timezone'            => date_default_timezone_get(),
    'memory_limit'        => ini_get('memory_limit'),
    'memory_used'         => fmt_bytes(memory_get_usage(true)),
    'memory_peak'         => fmt_bytes(memory_get_peak_usage(true)),
    'max_execution_time'  => ini_get('max_execution_time') . ' s',
    'max_input_size'      => ini_get('post_max_size'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'display_errors'      => ini_get('display_errors') ? 'on' : 'off',
    'log_errors'          => ini_get('log_errors') ? 'on' : 'off',
    'error_log_path'      => ini_get('error_log') ?: '(default)',
    'extensions'          => array_values(array_filter([
        in_array('pdo_mysql', get_loaded_extensions()) ? 'pdo_mysql' : null,
        in_array('mbstring', get_loaded_extensions()) ? 'mbstring' : null,
        in_array('zip', get_loaded_extensions()) ? 'zip' : null,
        in_array('gd', get_loaded_extensions()) ? 'gd' : null,
        in_array('curl', get_loaded_extensions()) ? 'curl' : null,
        in_array('dom', get_loaded_extensions()) ? 'dom' : null,
        in_array('json', get_loaded_extensions()) ? 'json' : null,
        in_array('openssl', get_loaded_extensions()) ? 'openssl' : null,
    ])),
    'missing_extensions'  => array_values(array_filter([
        !in_array('pdo_mysql', get_loaded_extensions()) ? 'pdo_mysql' : null,
        !in_array('mbstring', get_loaded_extensions()) ? 'mbstring' : null,
        !in_array('zip', get_loaded_extensions()) ? 'zip' : null,
        !in_array('gd', get_loaded_extensions()) ? 'gd' : null,
        !in_array('dom', get_loaded_extensions()) ? 'dom' : null,
    ])),
    'mail_function'       => function_exists('mail') ? 'available' : 'missing',
];

// --- DATABÁZE ---
$db_info = [];
try {
    $version = $pdo->query("SELECT VERSION()")->fetchColumn();
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $tables = $pdo->query("
        SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME
    ")->fetchAll();
    $tab = [];
    $total_rows = 0; $total_size = 0;
    foreach ($tables as $t) {
        $rows = (int) ($t['TABLE_ROWS'] ?? 0);
        $size = (int) ($t['DATA_LENGTH'] ?? 0) + (int) ($t['INDEX_LENGTH'] ?? 0);
        $total_rows += $rows;
        $total_size += $size;
        $tab[] = [
            'name'    => $t['TABLE_NAME'],
            'rows'    => $rows,
            'size'    => fmt_bytes($size),
        ];
    }
    $db_info = [
        'connected'   => true,
        'version'     => $version,
        'name'        => $dbName,
        'table_count' => count($tab),
        'total_rows'  => $total_rows,
        'total_size'  => fmt_bytes($total_size),
        'tables'      => $tab,
    ];
} catch (Throwable $e) {
    $db_info = ['connected' => false, 'error' => $e->getMessage()];
}

// --- SCHÉMA — kontrola kritických sloupců ---
$schema = ['issues' => [], 'ok' => []];
$required_columns = [
    'vyrobky'      => ['obsah', 'obsah_jednotka', 'nutricni_hodnoty', 'vyrobni_cena', 'kalkulace_data', 'haccp_data', 'haccp_graf_id', 'ean'],
    'faktury'      => ['rucni', 'odb_nazev_snapshot', 'odb_ico_snapshot'],
    'odberatele'   => ['cenova_skupina_id', 'notif_emaily', 'login_email', 'heslo_hash'],
];
foreach ($required_columns as $tbl => $cols) {
    try {
        $existing = $pdo->prepare("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
        ");
        $existing->execute(['t' => $tbl]);
        $found = $existing->fetchAll(PDO::FETCH_COLUMN);
        if (empty($found)) {
            $schema['issues'][] = "Tabulka `$tbl` neexistuje";
            continue;
        }
        foreach ($cols as $col) {
            if (in_array($col, $found, true)) {
                $schema['ok'][] = "$tbl.$col";
            } else {
                $schema['issues'][] = "$tbl.$col chybí";
            }
        }
    } catch (Throwable $e) {
        $schema['issues'][] = "Kontrola `$tbl` selhala: " . $e->getMessage();
    }
}

// --- UPLOADS / SOUBOROVÝ SYSTÉM ---
// 🆕 v2.0.67 — Auto-vytvoř chybějící uploads složky (defenzivní self-heal)
$fs = [];
$uploadDirs = ['../uploads', '../uploads/vyrobky', '../uploads/odberatele', '../uploads/loga'];
foreach ($uploadDirs as $rel) {
    $path = __DIR__ . '/' . $rel;
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
        if (is_dir($path)) {
            @file_put_contents($path . '/.gitkeep', "# auto-created by admin_diagnostika.php\n");
        }
    }
    $real = realpath($path) ?: $path;
    $exists = is_dir($real);
    $info = [
        'path'     => $rel,
        'exists'   => $exists,
        'writable' => $exists && is_writable($real),
    ];
    if ($exists) {
        $sz = dir_size_recursive($real);
        $info['files'] = $sz['files'];
        $info['size']  = fmt_bytes($sz['size']);
    }
    $fs[] = $info;
}
$df = @disk_free_space(__DIR__);
$dt = @disk_total_space(__DIR__);
$disk = [
    'free'  => $df ? fmt_bytes((int) $df) : '?',
    'total' => $dt ? fmt_bytes((int) $dt) : '?',
    'used_pct' => ($df && $dt) ? round(100 - ($df / $dt * 100), 1) : null,
];

// --- API ENDPOINTY — existence souborů ---
$endpoints = [];
$expected = [
    'admin_faktury.php', 'admin_objednavky.php', 'admin_vyrobky.php',
    'admin_odberatele.php', 'admin_dodaci_listy.php', 'admin_nastaveni.php',
    'admin_haccp_dokumenty.php', 'admin_haccp_grafy.php', 'admin_cenove_skupiny.php',
    'admin_export_isdoc.php', 'admin_import_isdoc.php', 'admin_katalog_email.php',
    'admin_kategorie.php', 'admin_sazby_dph.php', 'admin_diagnostika.php', 'admin_zalohy.php',
    'admin_export_vyroby.php', 'admin_users.php', 'admin_moje_stitky.php',
    'config.php', '_admin_auth.php',
    'katalog.php', 'katalog_pdf.php', 'faktura.php', 'dodaci_list.php', 'vyrobek_pdf.php',
];
foreach ($expected as $f) {
    $p = __DIR__ . '/' . $f;
    $exists = file_exists($p);
    $endpoints[] = [
        'file'     => $f,
        'exists'   => $exists,
        'size'     => $exists ? fmt_bytes(filesize($p)) : null,
        'modified' => $exists ? date('Y-m-d H:i', filemtime($p)) : null,
    ];
}

// --- KOLIZE NÁZVŮ FUNKCÍ — sken všech api/*.php pro duplicitní function fooBar() ---
$collisions = [];
try {
    $files = glob(__DIR__ . '/*.php');
    $defs = []; // name => list of files
    foreach ($files as $f) {
        $code = @file_get_contents($f);
        if (!$code) continue;
        // Match `function fooBar(`  (mimo třídu)
        if (preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $code, $m)) {
            foreach ($m[1] as $name) {
                $defs[$name][] = basename($f);
            }
        }
    }
    foreach ($defs as $name => $where) {
        if (count($where) > 1) {
            $collisions[] = [
                'function' => $name,
                'files'    => array_unique($where),
            ];
        }
    }
} catch (Throwable $e) {
    $collisions = ['error' => $e->getMessage()];
}

// --- POSLEDNÍ ZÁZNAMY Z ERROR LOGU ---
$logs = [];
$logPaths = [
    ini_get('error_log'),
    __DIR__ . '/../error_log',
    __DIR__ . '/../../logs/php_error.log',
    '/tmp/php_errors.log',
];
$seen = [];
foreach ($logPaths as $p) {
    if (!$p || !file_exists($p) || isset($seen[$p])) continue;
    $seen[$p] = true;
    $size = filesize($p);
    if ($size > 0 && is_readable($p)) {
        // Posledních ~8 kB
        $read_size = min(8192, $size);
        $fp = fopen($p, 'r');
        if ($fp) {
            fseek($fp, max(0, $size - $read_size));
            $tail = fread($fp, $read_size);
            fclose($fp);
            $lines = array_filter(array_map('trim', explode("\n", $tail)));
            // Filtr — jen řádky, co vypadají jako PHP chyba
            $relevant = array_values(array_filter($lines, function ($l) {
                return preg_match('/(error|warning|fatal|exception|deprecated)/i', $l);
            }));
            $logs[] = [
                'path'  => $p,
                'size'  => fmt_bytes($size),
                'lines' => array_slice(array_reverse($relevant), 0, 15),
            ];
        }
    }
}

// --- NASTAVENÍ FIRMY (jen klíče k diagnostice) ---
$settings = [];
try {
    $keys_show = ['firma_nazev', 'firma_email', 'firma_telefon', 'firma_dic', 'firma_banka'];
    $rows = $pdo->query("SELECT klic, hodnota FROM nastaveni")->fetchAll();
    foreach ($rows as $r) {
        if (in_array($r['klic'], $keys_show, true)) {
            $settings[$r['klic']] = $r['hodnota'];
        }
    }
    $settings['_pocet_klicu'] = count($rows);
} catch (Throwable $e) {
    $settings = ['error' => $e->getMessage()];
}

// --- POČTY ZÁZNAMŮ V KRITICKÝCH TABULKÁCH ---
$counts = [];
foreach (['odberatele', 'vyrobky', 'objednavky', 'dodaci_listy', 'faktury', 'kategorie_vyrobku', 'admin_users'] as $t) {
    try {
        $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    } catch (Throwable $e) {
        $counts[$t] = null;
    }
}

// --- 🚨 JS CHYBY Z PROHLÍŽEČŮ (klient_chyby) ---
$js_errors = ['rows' => [], 'stats' => null, 'top' => null];
try {
    // Stats
    $js_errors['stats'] = [
        '1h'  => (int) $pdo->query("SELECT COUNT(*) FROM klient_chyby WHERE cas > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn(),
        '24h' => (int) $pdo->query("SELECT COUNT(*) FROM klient_chyby WHERE cas > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
        '7d'  => (int) $pdo->query("SELECT COUNT(*) FROM klient_chyby WHERE cas > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
        'celkem' => (int) $pdo->query("SELECT COUNT(*) FROM klient_chyby")->fetchColumn(),
    ];
    $js_errors['rows'] = $pdo->query("
        SELECT id, cas, app, msg, source, line, col, url, user_info
        FROM klient_chyby
        ORDER BY cas DESC
        LIMIT 30
    ")->fetchAll();
    $js_errors['top'] = $pdo->query("
        SELECT msg, COUNT(*) AS pocet, MAX(cas) AS posledni
        FROM klient_chyby
        WHERE cas > DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY msg
        ORDER BY pocet DESC
        LIMIT 5
    ")->fetchAll();
} catch (Throwable $e) {
    $js_errors['error'] = $e->getMessage();
}

// --- 🔑 AUTH LOG — posledních 20 přihlášení (z tabulky prihlaseni_pokusy) ---
$auth_log = ['rows' => [], 'stats' => null];
try {
    $auth_log['stats'] = [
        'uspesnych_24h' => (int) $pdo->query("SELECT COUNT(*) FROM prihlaseni_pokusy WHERE uspesny = 1 AND cas > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
        'neuspesnych_24h' => (int) $pdo->query("SELECT COUNT(*) FROM prihlaseni_pokusy WHERE uspesny = 0 AND cas > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
    ];
    $auth_log['rows'] = $pdo->query("
        SELECT email, ip, typ, uspesny, cas
        FROM prihlaseni_pokusy
        ORDER BY cas DESC
        LIMIT 20
    ")->fetchAll();
} catch (Throwable $e) {
    $auth_log['error'] = $e->getMessage();
}

// --- 📝 NEDÁVNO ZMĚNĚNÉ SOUBORY (uplynulých 48h) ---
$recent_changes = [];
try {
    $scanDirs = [__DIR__, __DIR__ . '/../admin'];
    $cutoff = time() - 48 * 3600;
    foreach ($scanDirs as $dir) {
        if (!is_dir($dir)) continue;
        foreach (new DirectoryIterator($dir) as $f) {
            if (!$f->isFile()) continue;
            if (!in_array($f->getExtension(), ['php', 'js', 'css', 'html', 'json'], true)) continue;
            $mtime = $f->getMTime();
            if ($mtime < $cutoff) continue;
            $recent_changes[] = [
                'soubor' => basename(dirname($f->getPathname())) . '/' . $f->getFilename(),
                'velikost' => fmt_bytes($f->getSize()),
                'cas' => date('Y-m-d H:i', $mtime),
                'pred' => $mtime, // pro sort
            ];
        }
    }
    usort($recent_changes, fn($a, $b) => $b['pred'] - $a['pred']);
    foreach ($recent_changes as &$r) unset($r['pred']);
} catch (Throwable $e) {
    $recent_changes = [['error' => $e->getMessage()]];
}

// --- 🐬 MYSQL KONFIGURACE — důležité pro debug timezone, charset, max_packet ---
$mysql_cfg = [];
try {
    $vars = ['time_zone', 'character_set_server', 'character_set_database',
             'max_allowed_packet', 'max_connections', 'innodb_buffer_pool_size',
             'wait_timeout', 'interactive_timeout', 'sql_mode'];
    foreach ($vars as $v) {
        $r = $pdo->query("SHOW VARIABLES LIKE '$v'")->fetch();
        if ($r) {
            $val = $r['Value'];
            // Formátuj velikost pro bytes
            if (in_array($v, ['max_allowed_packet', 'innodb_buffer_pool_size'], true) && is_numeric($val)) {
                $val = fmt_bytes((int) $val);
            }
            $mysql_cfg[$v] = $val;
        }
    }
    // Aktuální čas DB vs PHP (kontrola timezone consistency)
    $db_now = $pdo->query("SELECT NOW(), UTC_TIMESTAMP()")->fetch(PDO::FETCH_NUM);
    $mysql_cfg['_db_NOW'] = $db_now[0];
    $mysql_cfg['_db_UTC'] = $db_now[1];
    $mysql_cfg['_php_local'] = date('Y-m-d H:i:s');
    $mysql_cfg['_php_utc'] = gmdate('Y-m-d H:i:s');
} catch (Throwable $e) {
    $mysql_cfg = ['error' => $e->getMessage()];
}

// --- ⚡ OPCACHE STATUS (pokud běží) ---
$opcache = null;
if (function_exists('opcache_get_status')) {
    try {
        $s = @opcache_get_status(false);
        if ($s) {
            $opcache = [
                'enabled' => $s['opcache_enabled'] ?? false,
                'memory_used' => isset($s['memory_usage']['used_memory']) ? fmt_bytes((int) $s['memory_usage']['used_memory']) : '?',
                'memory_free' => isset($s['memory_usage']['free_memory']) ? fmt_bytes((int) $s['memory_usage']['free_memory']) : '?',
                'cached_scripts' => $s['opcache_statistics']['num_cached_scripts'] ?? 0,
                'hits' => $s['opcache_statistics']['hits'] ?? 0,
                'misses' => $s['opcache_statistics']['misses'] ?? 0,
                'hit_rate' => isset($s['opcache_statistics']['opcache_hit_rate'])
                    ? round((float) $s['opcache_statistics']['opcache_hit_rate'], 1) . ' %' : '?',
                'restarts' => ($s['opcache_statistics']['oom_restarts'] ?? 0)
                            + ($s['opcache_statistics']['hash_restarts'] ?? 0)
                            + ($s['opcache_statistics']['manual_restarts'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        $opcache = ['error' => $e->getMessage()];
    }
} else {
    $opcache = ['enabled' => false, 'reason' => 'opcache extension nedostupný'];
}

// --- 🩺 LIVE HEALTH CHECK — voláme klíčové GET endpointy (HEAD-style) ---
$live_check = [];
$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
      . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$test_paths = [
    '/' => 'Frontend index',
    '/admin/' => 'Admin index',
    '/api/check_install.php' => 'Install check',
];
foreach ($test_paths as $path => $label) {
    $url = $base . $path;
    $start = microtime(true);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'HEAD',
            'timeout' => 3,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $hdrs = @get_headers($url, true, $ctx);
    $elapsed = round((microtime(true) - $start) * 1000);
    $code = 0;
    if (is_array($hdrs) && !empty($hdrs[0]) && preg_match('/HTTP\/[\d.]+\s+(\d+)/', $hdrs[0], $m)) {
        $code = (int) $m[1];
    }
    $live_check[] = [
        'label' => $label,
        'url'   => $path,
        'http'  => $code,
        'ms'    => $elapsed,
        'ok'    => $code >= 200 && $code < 500,
    ];
}

// --- 📊 PERFORMANCE — počet pomalých queries (orientačně) ---
$perf = [];
try {
    $slow = $pdo->query("SHOW STATUS LIKE 'Slow_queries'")->fetch();
    $queries = $pdo->query("SHOW STATUS LIKE 'Questions'")->fetch();
    $uptime = $pdo->query("SHOW STATUS LIKE 'Uptime'")->fetch();
    $perf = [
        'slow_queries' => $slow ? (int) $slow['Value'] : 0,
        'total_queries' => $queries ? (int) $queries['Value'] : 0,
        'uptime_seconds' => $uptime ? (int) $uptime['Value'] : 0,
    ];
    if ($perf['uptime_seconds'] > 0) {
        $perf['uptime_hours'] = round($perf['uptime_seconds'] / 3600, 1);
        $perf['queries_per_sec'] = round($perf['total_queries'] / $perf['uptime_seconds'], 1);
    }
} catch (Throwable $e) {
    $perf = ['error' => $e->getMessage()];
}

// --- 🔍 SESSION INFO — co je teď v $_SESSION pro admina (redacted) ---
$session_info = [
    'admin_id'    => $_SESSION['admin_id'] ?? null,
    'admin_jmeno' => $_SESSION['admin_jmeno'] ?? null,
    'admin_role'  => $_SESSION['admin_role'] ?? null,
    'session_id'  => substr(session_id(), 0, 8) . '...',
    'cookie_name' => session_name(),
    'session_age' => isset($_SESSION['_started']) ? (time() - (int) $_SESSION['_started']) . ' s' : '?',
];

// --- OUTPUT ---
json_response([
    'ok'             => true,
    'generated'      => date('c'),
    'system'         => $system,
    'database'       => $db_info,
    'schema'         => $schema,
    'filesystem'     => $fs,
    'disk'           => $disk,
    'endpoints'      => $endpoints,
    'collisions'     => $collisions,
    'logs'           => $logs,
    'settings'       => $settings,
    'counts'         => $counts,
    // ===== NOVÉ =====
    'js_errors'      => $js_errors,
    'auth_log'       => $auth_log,
    'recent_changes' => $recent_changes,
    'mysql_cfg'      => $mysql_cfg,
    'opcache'        => $opcache,
    'live_check'     => $live_check,
    'perf'           => $perf,
    'session_info'   => $session_info,
]);
