<?php
/**
 * 🧪 APPEK smoke-chain — společná knihovna (config, HTTP klient, DB, generátory, BOM replika).
 * Vyžadují ji run.php (orchestrace) a verify.php (invarianty). Není vstupní bod.
 * Viz run.php pro popis celého testu a použití.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('memory_limit', '2048M');
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Prague');

$ARGS = $argv; array_shift($ARGS);
$CMD = $ARGS[0] ?? 'help';
$OPT = [];
foreach ($ARGS as $a) if (preg_match('/^--([a-z0-9_-]+)(?:=(.*))?$/', $a, $m)) $OPT[$m[1]] = $m[2] ?? '1';
function opt(string $k, $def = null) { global $OPT; return $OPT[$k] ?? $def; }

$DIR_BASE = rtrim(str_replace('~', (string) getenv('HOME'), (string) opt('dir', getenv('HOME') . '/appek-harness/runs')), '/');
$RUN = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) opt('run', 'R' . date('mdHi'))));
$RDIR = "$DIR_BASE/$RUN";
@mkdir($RDIR, 0700, true);

/** Konfigurace běhu — při `run` se zapíše, ostatní příkazy ji čtou. */
function cfg(?string $k = null) {
    static $c = null;
    global $RDIR, $CMD;
    if ($c === null) {
        $f = "$RDIR/config.json";
        if ($CMD === 'run' && !is_file($f)) {
            $start = (string) opt('start', date('Y-m-d', strtotime('+28 days')));
            $days  = (int) opt('days', 20);
            $dates = []; $t = strtotime($start);
            while (count($dates) < $days) { if ((int) date('N', $t) <= 5) $dates[] = date('Y-m-d', $t); $t = strtotime('+1 day', $t); }
            $last = $dates[count($dates) - 1];
            $probe = date('Y-m-d', strtotime("$last +10 days"));
            while ((int) date('N', strtotime($probe)) > 5) $probe = date('Y-m-d', strtotime("$probe +1 day"));
            $c = [
                'run' => $GLOBALS['RUN'], 'tag' => 'SMK-' . $GLOBALS['RUN'],
                'root' => rtrim((string) opt('root', ''), '/'), 'base' => rtrim((string) opt('base', ''), '/'),
                'resolve' => opt('resolve') ?: null, 'customers' => (int) opt('customers', 50),
                'conc' => max(1, (int) opt('conc', 6)), 'dates' => $dates, 'probe_date' => $probe,
                'today' => date('Y-m-d'), 'started' => date('c'), 'month_end' => date('Y-m-t', strtotime($last)),
                'no_sink' => (bool) opt('no-sink', false), 'skip_probes' => (bool) opt('skip-probes', false),
            ];
            if (!$c['root'] || !is_file($c['root'] . '/api/config.php')) die("❌ --root musí ukazovat na instalaci APPEK (api/config.php)\n");
            if (!$c['base']) die("❌ --base chybí\n");
            file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            if (!is_file($f)) die("❌ Neexistuje $f (spusť nejdřív `run`)\n");
            $c = json_decode(file_get_contents($f), true);
        }
    }
    return $k === null ? $c : ($c[$k] ?? null);
}
function dates_last(): string { $d = cfg('dates'); return $d[count($d) - 1]; }
function rpath(string $f): string { global $RDIR; return "$RDIR/$f"; }
function jload(string $f, $def = null) { $p = rpath($f); return is_file($p) ? json_decode(file_get_contents($p), true) : $def; }
function jsave(string $f, $data): void { file_put_contents(rpath($f), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)); }
function jappend(string $f, $row): void { file_put_contents(rpath($f), json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX); }
function jlines(string $pattern): array {
    $out = [];
    foreach (glob(rpath($pattern)) ?: [] as $p) foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) { $r = json_decode($l, true); if ($r !== null) $out[] = $r; }
    return $out;
}
function L(string $m): void { $line = '[' . date('H:i:s') . '] ' . $m . "\n"; echo $line; file_put_contents(rpath('run.log'), $line, FILE_APPEND | LOCK_EX); }
function phase_done(string $p): bool { return is_file(rpath("phase-$p.done")); }
function mark_done(string $p, $info = []): void { jsave("phase-$p.done", ['at' => date('c'), 'info' => $info]); }
function is_local_base(): bool { $h = (string) parse_url((string) cfg('base'), PHP_URL_HOST); return in_array($h, ['127.0.0.1', 'localhost', '::1'], true) || str_ends_with($h, '.test') || str_ends_with($h, '.localhost'); }

/** Tajné údaje běhu (heslo harness admina, seed hesel zákazníků) — nikdy se netisknou. */
function secret(): array {
    $f = rpath('secret.json');
    if (!is_file($f)) {
        $s = ['admin_email' => 'smoke-harness+' . strtolower($GLOBALS['RUN']) . '@appek.invalid',
              'admin_pw' => bin2hex(random_bytes(12)), 'seed' => bin2hex(random_bytes(16))];
        file_put_contents($f, json_encode($s)); @chmod($f, 0600);
    }
    return json_decode(file_get_contents($f), true);
}
function cust_pw(int $i): string { return 'Smk-' . substr(hash_hmac('sha256', "cust:$i", secret()['seed']), 0, 18); }

/** CLI bootstrap aplikace → PDO (stejný vzor jako scripts/test-money-paths.php). */
function app_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $_SERVER['HTTP_HOST'] = parse_url((string) cfg('base'), PHP_URL_HOST) ?: 'localhost';
    $_SERVER['HTTPS'] = 'on'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start(); require_once cfg('root') . '/api/config.php'; ob_end_clean();
    ini_set('display_errors', '1');
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Sjednoť collation spojení — sklad_pohyby_v2 je utf8mb4_general_ci, ostatní unicode_ci;
    // bez toho padají porovnání sloupec↔parametr na „Illegal mix of collations".
    try { $pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'"); } catch (Throwable $e) {}
    return $pdo;
}
function q(string $sql, array $p = []): array { $st = app_db()->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }
function q1(string $sql, array $p = []) { $st = app_db()->prepare($sql); $st->execute($p); return $st->fetchColumn(); }

final class Stats {
    public static array $s = [];
    public static function add(string $label, int $code, float $ms): void {
        if (!isset(self::$s[$label])) self::$s[$label] = ['n' => 0, 'ms' => 0.0, 'codes' => [], 'samples' => []];
        $x = &self::$s[$label];
        $x['n']++; $x['ms'] += $ms; $x['codes'][$code] = ($x['codes'][$code] ?? 0) + 1;
        if (count($x['samples']) < 4000) $x['samples'][] = round($ms, 1); else $x['samples'][random_int(0, 3999)] = round($ms, 1);
    }
    public static function save(string $f): void { jsave($f, self::$s); }
}
/** Pojistka: po 6 tvrdých chybách v řadě (WAF/limit) běh zastav. */
final class Breaker {
    public static int $bad = 0;
    public static function check(int $code, bool $isJson, string $label, string $raw): void {
        $hard = in_array($code, [0, 429, 503, 508], true) || ($code === 403 && !$isJson && !str_contains($label, 'faktura.php') && !str_contains($label, 'dodaci_list.php'));
        self::$bad = $hard ? self::$bad + 1 : 0;
        if (self::$bad >= 6) throw new RuntimeException("BREAKER: $code na $label (6x po sobe) - WAF/limit? " . substr($raw, 0, 160));
    }
}
/** HTTP klient s vlastní cookie jar (APPEKSID). */
final class Http {
    public string $cookie = '';
    public ?string $csrf = null;
    public static array $errors = [];
    public static function curlExtra(): array {
        if (!cfg('resolve')) return [];
        $host = parse_url((string) cfg('base'), PHP_URL_HOST); $port = parse_url((string) cfg('base'), PHP_URL_SCHEME) === 'https' ? 443 : 80;
        return [CURLOPT_RESOLVE => ["$host:$port:" . cfg('resolve')], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];
    }
    public function req(string $method, string $path, $body = null, ?string $label = null, bool $raw = false): array {
        $ch = curl_init(cfg('base') . '/' . ltrim($path, '/'));
        $h = ['Accept: application/json, text/html', 'User-Agent: Mozilla/5.0 (APPEK smoke-chain ' . cfg('run') . ')'];
        if ($this->cookie !== '') $h[] = 'Cookie: APPEKSID=' . $this->cookie;
        if ($this->csrf && $method !== 'GET') $h[] = 'X-CSRF-Token: ' . $this->csrf;
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 15];
        if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
        $opts[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts + self::curlExtra());
        $t0 = microtime(true);
        $resp = curl_exec($ch);
        $ms = (microtime(true) - $t0) * 1000;
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsz = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);
        $hdr = $resp === false ? '' : substr($resp, 0, $hsz);
        $txt = $resp === false ? $err : substr($resp, $hsz);
        if (preg_match_all('/^Set-Cookie:\s*APPEKSID=([^;\r\n]*)/mi', $hdr, $mm)) {
            $v = end($mm[1]); $this->cookie = ($v === 'deleted' || $v === '') ? '' : $v;
        }
        $json = $raw ? null : json_decode((string) $txt, true);
        $label = $label ?? ($method . ' ' . self::label($path));
        Stats::add($label, $code, $ms);
        if ($code >= 500 || $code === 0) self::$errors[] = ['t' => date('c'), 'label' => $label, 'code' => $code, 'body' => mb_substr((string) $txt, 0, 400), 'req' => is_array($body) ? mb_substr(json_encode($body, JSON_UNESCAPED_UNICODE), 0, 300) : null];
        Breaker::check($code, $raw || is_array($json), $label, (string) $txt);
        return ['code' => $code, 'json' => is_array($json) ? $json : null, 'raw' => (string) $txt, 'ms' => $ms];
    }
    public static function label(string $path): string {
        $p = parse_url($path); $b = basename($p['path'] ?? $path);
        parse_str($p['query'] ?? '', $qq);
        return $b . (isset($qq['action']) ? '?action=' . $qq['action'] : '');
    }
}
/** Admin session (login + CSRF + auto-reauth). */
final class Admin {
    public Http $h;
    public function __construct() { $this->h = new Http(); $this->login(); }
    public function login(): void {
        $s = secret(); $this->h->cookie = ''; $this->h->csrf = null;
        $r = $this->h->req('POST', 'api/admin_login.php', ['email' => $s['admin_email'], 'heslo' => $s['admin_pw']], 'POST admin_login.php');
        if ($r['code'] !== 200 || empty($r['json']['csrf_token'])) throw new RuntimeException('Admin login selhal: ' . $r['code'] . ' ' . substr($r['raw'], 0, 200));
        $this->h->csrf = $r['json']['csrf_token'];
    }
    public function call(string $m, string $path, $body = null, ?string $label = null, bool $raw = false): array {
        $r = $this->h->req($m, $path, $body, $label, $raw);
        if ($r['code'] === 401) { $this->login(); $r = $this->h->req($m, $path, $body, $label, $raw); }
        elseif ($r['code'] === 403 && (($r['json']['error'] ?? '') === 'csrf_invalid')) {
            $w = $this->h->req('GET', 'api/whoami.php', null, 'GET whoami.php');
            if (!empty($w['json']['csrf_token'])) $this->h->csrf = $w['json']['csrf_token'];
            $r = $this->h->req($m, $path, $body, $label, $raw);
        }
        return $r;
    }
}
/** Paralelní POST stejného požadavku z několika admin sessions (sondy na souběh). Vrací HTTP kódy. */
function parallel_post(array $admins, string $path, array $body): array {
    $mh = curl_multi_init(); $hs = [];
    foreach ($admins as $A) {
        $ch = curl_init(cfg('base') . '/' . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: Mozilla/5.0 (APPEK smoke-chain)', 'X-CSRF-Token: ' . $A->h->csrf, 'Cookie: APPEKSID=' . $A->h->cookie]] + Http::curlExtra());
        curl_multi_add_handle($mh, $ch); $hs[] = $ch;
    }
    do { curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run);
    $codes = [];
    foreach ($hs as $ch) { $codes[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
    return $codes;
}

// BOM replika (nezávislá na api/_bom_lib.php — kontrola, ne znovupoužití produkční logiky)
function bom_rows_cached(int $vid): array {
    static $c = [];
    if (!isset($c[$vid])) $c[$vid] = q('SELECT vs.surovina_id, vs.slozka_vyrobek_id, vs.mnozstvi, COALESCE(v2.sleduje_sklad,0) AS sub_ss FROM vyrobek_suroviny vs LEFT JOIN vyrobky v2 ON v2.id = vs.slozka_vyrobek_id WHERE vs.vyrobek_id = :v', ['v' => $vid]);
    return $c[$vid];
}
function bom_x(int $vid, float $qty, array &$sur, array &$pol, int $depth = 0, array $path = []): void {
    if ($qty <= 0 || $depth > 8 || isset($path[$vid])) return;
    $path[$vid] = 1;
    foreach (bom_rows_cached($vid) as $r) {
        $mn = (float) $r['mnozstvi']; if ($mn <= 0) continue;
        if ($r['surovina_id']) $sur[(int) $r['surovina_id']] = ($sur[(int) $r['surovina_id']] ?? 0) + $qty * $mn;
        elseif ($r['slozka_vyrobek_id']) {
            $sv = (int) $r['slozka_vyrobek_id'];
            if ((int) $r['sub_ss'] === 1) $pol[$sv] = ($pol[$sv] ?? 0) + $qty * $mn;
            else bom_x($sv, $qty * $mn, $sur, $pol, $depth + 1, $path);
        }
    }
}

/** Všechny objednávky zákaznické fáze (id => objednávka). */
function all_orders(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $o = [];
    foreach (jlines('customers-*.jsonl') as $c) foreach ($c['orders'] as $ord) { $ord['odb'] = $c['id'] ?? null; $ord['ci'] = $c['i']; $o[$ord['id']] = $ord; }
    ksort($o); return $cache = $o;
}
