<?php
/**
 * 📊 ADMIN — Účetní integrace: POHODA (mServer + XML soubor) a ABRA Flexi / FlexiBee (REST).
 *
 * Jeden endpoint pro oba systémy: sdílí nastavení/heslovou masku, SSRF politiku, log, stav exportu
 * i autorizaci (require_admin + CSRF na mutacích, readonly role blokovaná centrálně) → jedna brána
 * místo dvou kopií. Systém se volí parametrem `system` nebo prefixem akce.
 *
 *   GET  ?action=config                          → nastavení obou systémů (hesla maskovaná) + poslední synchronizace
 *   POST ?action=save        {system, …}         → uloží nastavení (jen super admin)
 *   POST ?action=test        {system}            → test připojení
 *   GET  ?action=stav&faktura_id=N               → stav exportu faktury do obou systémů
 *   POST ?action=pohoda_xml  {faktura_id} | {od, do, jen_neodeslane}  → stáhne dataPack XML (Windows-1250)
 *   POST ?action=pohoda_send {faktura_id, force} → odešle fakturu na mServer
 *   POST ?action=pohoda_send_bulk {od, do, jen_neodeslane, force}
 *   POST ?action=pohoda_adresar {rezim: soubor|odeslat} → adresář odběratelů
 *   GET  ?action=pohoda_prijate&od=&do=          → přijaté faktury (mServer list request)
 *   POST ?action=flexi_send  {faktura_id, force}
 *   POST ?action=flexi_send_bulk {od, do, jen_neodeslane, force}
 *   POST ?action=flexi_adresar                   → odběratelé → adresar
 *   POST ?action=flexi_cenik                     → výrobky → cenik
 *   GET  ?action=flexi_prijate&od=&do=           → přijaté faktury (faktura-prijata)
 *   GET  ?action=log&system=&limit=100           → audit log synchronizací
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_ucetni_lib.php';
require_once __DIR__ . '/_pohoda.php';
require_once __DIR__ . '/_flexibee.php';

cors_headers();
$admin = require_admin();
$pdo = db();
ucetni_ensure_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
$d = $method === 'POST' ? json_input() : [];

const UCETNI_MUTACE = ['save', 'test', 'pohoda_xml', 'pohoda_send', 'pohoda_send_bulk', 'pohoda_adresar',
                       'flexi_send', 'flexi_send_bulk', 'flexi_adresar', 'flexi_cenik'];
const UCETNI_CTENI  = ['config', 'stav', 'pohoda_prijate', 'flexi_prijate', 'log'];

if (in_array($action, UCETNI_MUTACE, true) && $method !== 'POST') json_error('Tato akce vyžaduje POST', 405);
if (in_array($action, UCETNI_CTENI, true) && $method !== 'GET') json_error('Tato akce vyžaduje GET', 405);
if (!in_array($action, UCETNI_MUTACE, true) && !in_array($action, UCETNI_CTENI, true)) json_error('Neznámá akce', 400);

/** Období z požadavku (YYYY-MM-DD, od ≤ do, max 366 dní). */
function ucetni_obdobi(array $src): array {
    $od = (string) ($src['od'] ?? ''); $do = (string) ($src['do'] ?? '');
    if (!ucetni_datum_ok($od) || !ucetni_datum_ok($do)) json_error('Zadej platné období (od – do).', 400);
    if ($od > $do) json_error('Datum „od" je po datu „do".', 400);
    if ((strtotime($do) - strtotime($od)) / 86400 > 366) json_error('Období může být nejvýš 1 rok.', 400);
    return [$od, $do];
}

function ucetni_posledni_sync(PDO $pdo, string $system): ?array {
    try {
        $st = $pdo->prepare("SELECT action, success, message, target_ref, created_at FROM accounting_sync_log
                             WHERE system = :s ORDER BY id DESC LIMIT 1");
        $st->execute(['s' => $system]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/** Stáhne soubor (XML) a ukončí. */
function ucetni_download(string $content, string $filename): void {
    header('Content-Type: application/xml; charset=windows-1250');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

/** Je původní faktura dobropisu už v POHODĚ (export OK)? */
function pohoda_puvodni_v_pohode(PDO $pdo, array $inv, array $vBalicku = []): bool {
    if (empty($inv['je_dobropis']) || empty($inv['puvodni_id'])) return false;
    if (in_array($inv['puvodni_id'], $vBalicku, true)) return true;
    $s = ucetni_state_get($pdo, 'pohoda', (int) $inv['puvodni_id']);
    return $s && !empty($s['ok_at']);
}

function pohoda_opt(array $cfg): array {
    return ['predkontace' => $cfg['pohoda_predkontace'] ?? '', 'cleneni' => $cfg['pohoda_cleneni'] ?? ''];
}

/**
 * Odešle sadu faktur na mServer (po 20 v jednom dataPacku). Vrací výsledky per faktura.
 * $faktury = [inv, …] (už prošlé idempotencí).
 */
function pohoda_odeslat_faktury(PDO $pdo, array $cfg, array $faktury): array {
    $vysledky = [];
    $odeslane = [];
    foreach (array_chunk($faktury, 20) as $chunk) {
        $items = []; $mapa = [];
        foreach ($chunk as $inv) {
            $opt = pohoda_opt($cfg);
            $opt['puvodni_v_pohode'] = pohoda_puvodni_v_pohode($pdo, $inv, $odeslane);
            $b = pohoda_xml_invoice($inv, $opt);
            if (!$b['ok']) {
                ucetni_state_set($pdo, 'pohoda', $inv['id'], false, 'mserver', null, null, null, $b['error']);
                ucetni_log($pdo, 'pohoda', 'export_faktura', false, $b['error'], 'faktura', $inv['id'], $inv['cislo']);
                $vysledky[] = ['faktura_id' => $inv['id'], 'cislo' => $inv['cislo'], 'ok' => false, 'zprava' => $b['error']];
                continue;
            }
            $items[] = ['id' => 'fa-' . $inv['id'], 'xml' => $b['xml']];
            $mapa['fa-' . $inv['id']] = $inv;
        }
        if (!$items) continue;
        $pack = pohoda_datapack($items, (string) $cfg['pohoda_ico'], 'appek-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)), 'APPEK export faktur');
        $r = pohoda_send($cfg, $pack, 120);
        $poItemech = [];
        if ($r['parsed']) foreach ($r['parsed']['items'] as $it) $poItemech[$it['id']] = $it;
        foreach ($mapa as $packId => $inv) {
            $it = $poItemech[$packId] ?? null;
            if ($it && in_array($it['state'], ['ok', 'warning'], true)) {
                $cislo = $it['produced_number'] ?: $inv['cislo'];
                $msg = pohoda_item_messages($it);
                ucetni_state_set($pdo, 'pohoda', $inv['id'], true, 'mserver', $it['produced_id'] ?: null, $cislo, $inv['hash']);
                ucetni_log($pdo, 'pohoda', 'export_faktura', true, 'Importováno do POHODY' . ($it['produced_id'] ? ' (ID ' . $it['produced_id'] . ')' : '') . ($msg ? ' — ' . $msg : ''), 'faktura', $inv['id'], $inv['cislo']);
                $vysledky[] = ['faktura_id' => $inv['id'], 'cislo' => $inv['cislo'], 'ok' => true, 'externi_id' => $it['produced_id'], 'externi_cislo' => $cislo, 'zprava' => $msg];
                $odeslane[] = $inv['id'];
            } else {
                $err = $it ? (pohoda_item_messages($it) ?: 'POHODA doklad nepřijala.') : ($r['error'] ?? 'POHODA nevrátila výsledek pro tento doklad.');
                ucetni_state_set($pdo, 'pohoda', $inv['id'], false, 'mserver', null, null, null, $err);
                ucetni_log($pdo, 'pohoda', 'export_faktura', false, $err, 'faktura', $inv['id'], $inv['cislo'], mb_substr(@iconv('WINDOWS-1250', 'UTF-8//IGNORE', (string) $r['raw']) ?: '', 0, 2000));
                $vysledky[] = ['faktura_id' => $inv['id'], 'cislo' => $inv['cislo'], 'ok' => false, 'zprava' => $err];
            }
        }
    }
    return $vysledky;
}

function flexi_odeslat_fakturu(PDO $pdo, array $cfg, array $inv): array {
    $r = flexi_export_invoice($cfg, $inv);
    if ($r['ok']) {
        ucetni_state_set($pdo, 'flexibee', $inv['id'], true, 'rest', (string) $r['externi_id'], $r['externi_cislo'], $inv['hash']);
        $st = $r['stats'] ?? [];
        $co = !empty($st['updated']) && (int) $st['updated'] > 0 ? 'aktualizováno' : 'vytvořeno';
        ucetni_log($pdo, 'flexibee', 'export_faktura', true, "Ve FlexiBee $co (ID {$r['externi_id']})", 'faktura', $inv['id'], $inv['cislo'], $r['raw'] ?? '');
        return ['faktura_id' => $inv['id'], 'cislo' => $inv['cislo'], 'ok' => true, 'externi_id' => $r['externi_id'], 'externi_cislo' => $r['externi_cislo'], 'zprava' => ucfirst($co)];
    }
    ucetni_state_set($pdo, 'flexibee', $inv['id'], false, 'rest', null, null, null, (string) $r['error']);
    ucetni_log($pdo, 'flexibee', 'export_faktura', false, (string) $r['error'], 'faktura', $inv['id'], $inv['cislo'], $r['raw'] ?? '');
    return ['faktura_id' => $inv['id'], 'cislo' => $inv['cislo'], 'ok' => false, 'zprava' => $r['error']];
}

/** Načte faktury pro hromadný export + aplikuje idempotenci. */
function ucetni_priprav_davku(PDO $pdo, string $system, array $ids, bool $force): array {
    $k = []; $preskoceno = [];
    foreach ($ids as $id) {
        $inv = ucetni_nacti_fakturu($pdo, $id);
        if (!$inv) continue;
        $idem = ucetni_idempotence(ucetni_state_get($pdo, $system, $id), $inv['hash'], $force, $system === 'pohoda' ? 'mserver' : 'rest');
        if (!$idem['pokracovat']) { $preskoceno[] = ['faktura_id' => $id, 'cislo' => $inv['cislo'], 'duvod' => $idem['duvod'], 'zprava' => $idem['zprava']]; continue; }
        $k[] = $inv;
    }
    return [$k, $preskoceno];
}

function ucetni_souhrn(array $vysledky, array $preskoceno): array {
    $ok = count(array_filter($vysledky, fn($v) => $v['ok']));
    return ['ok' => true, 'odeslano' => $ok, 'chyb' => count($vysledky) - $ok, 'preskoceno' => count($preskoceno),
            'vysledky' => $vysledky, 'preskocene' => $preskoceno];
}

set_time_limit(300);

try {
    // ───────────────────────── config ─────────────────────────
    if ($action === 'config') {
        $p = ucetni_settings_public($pdo, 'pohoda');
        $f = ucetni_settings_public($pdo, 'flexibee');
        json_response([
            'pohoda'      => $p + ['posledni' => ucetni_posledni_sync($pdo, 'pohoda')],
            'flexibee'    => $f + ['posledni' => ucetni_posledni_sync($pdo, 'flexibee')],
            'povolit_lan' => $p['ucetni_povolit_lan'],
            'muze_upravit'=> ($admin['role'] ?? '') === 'admin',
        ]);
    }

    // ───────────────────────── save ─────────────────────────
    if ($action === 'save') {
        require_super_admin();
        $system = (string) ($d['system'] ?? '');
        if (!ucetni_system_ok($system)) json_error('Neznámý systém', 400);
        $lan = null;
        if (array_key_exists('povolit_lan', $d)) {
            $lan = !empty($d['povolit_lan']);
            ucetni_nastaveni_set($pdo, 'ucetni_povolit_lan', $lan ? '1' : '0');
        }
        $r = ucetni_settings_save($pdo, $system, $d, $lan);
        if (!$r['ok']) json_error($r['error'], 400);
        ucetni_log($pdo, $system, 'nastaveni', true, 'Nastavení uloženo');
        json_response(['ok' => true, 'config' => ucetni_settings_public($pdo, $system)]);
    }

    // ───────────────────────── test ─────────────────────────
    if ($action === 'test') {
        $system = (string) ($d['system'] ?? '');
        if (!ucetni_system_ok($system)) json_error('Neznámý systém', 400);
        $cfg = ucetni_settings($pdo, $system);
        $r = $system === 'pohoda' ? pohoda_test($cfg) : flexi_test($cfg);
        ucetni_log($pdo, $system, 'test', $r['ok'], $r['ok'] ? ($r['message'] . (!empty($r['warning']) ? ' ' . $r['warning'] : '')) : $r['error']);
        json_response($r);
    }

    // ───────────────────────── stav faktury ─────────────────────────
    if ($action === 'stav') {
        $fid = (int) ($_GET['faktura_id'] ?? 0);
        if (!$fid) json_error('Chybí faktura_id', 400);
        $out = ['enabled' => [
            'pohoda'   => ucetni_settings($pdo, 'pohoda')['pohoda_enabled'] === '1',
            'flexibee' => ucetni_settings($pdo, 'flexibee')['flexibee_enabled'] === '1',
        ]];
        foreach (['pohoda', 'flexibee'] as $s) {
            $st = ucetni_state_get($pdo, $s, $fid);
            if ($st) {
                $inv = ucetni_nacti_fakturu($pdo, $fid);
                $st['zmeneno_od_exportu'] = $inv && !empty($st['ok_at']) && $st['hash'] && !hash_equals((string) $st['hash'], $inv['hash']);
                unset($st['hash']);
            }
            $out[$s] = $st;
        }
        json_response($out);
    }

    // ───────────────────────── log ─────────────────────────
    if ($action === 'log') {
        $system = (string) ($_GET['system'] ?? '');
        $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
        $sql = "SELECT id, system, action, target_type, target_id, target_ref, success, message, response_excerpt, created_by, created_at
                FROM accounting_sync_log" . (ucetni_system_ok($system) ? " WHERE system = :s" : '') . " ORDER BY id DESC LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute(ucetni_system_ok($system) ? ['s' => $system] : []);
        json_response(['zaznamy' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ═════════════════════════ POHODA ═════════════════════════
    if (str_starts_with($action, 'pohoda_')) {
        $cfg = ucetni_settings($pdo, 'pohoda');

        if ($action === 'pohoda_xml') {
            if (($cfg['pohoda_ico'] ?? '') === '') json_error('Nejdřív v Nastavení → Integrace vyplň IČO účetní jednotky v POHODĚ (je v hlavičce XML).', 400);
            $fid = (int) ($d['faktura_id'] ?? 0);
            if ($fid) {
                $ids = [$fid]; $nazev = 'pohoda_faktura_' . $fid;
            } else {
                [$od, $do] = ucetni_obdobi($d);
                $ids = ucetni_faktury_v_obdobi($pdo, 'pohoda', $od, $do, !empty($d['jen_neodeslane']), 1000);
                $nazev = 'pohoda_faktury_' . str_replace('-', '', $od) . '_' . str_replace('-', '', $do);
                if (!$ids) json_error(!empty($d['jen_neodeslane']) ? 'V tomto období nejsou žádné neexportované faktury.' : 'V tomto období nejsou žádné faktury.', 404);
            }
            $items = []; $chyby = []; $vBalicku = []; $zahrnute = [];
            foreach ($ids as $id) {
                $inv = ucetni_nacti_fakturu($pdo, $id);
                if (!$inv) { if ($fid) json_error('Faktura nenalezena', 404); continue; }
                $opt = pohoda_opt($cfg);
                $opt['puvodni_v_pohode'] = pohoda_puvodni_v_pohode($pdo, $inv, $vBalicku);
                $b = pohoda_xml_invoice($inv, $opt);
                if (!$b['ok']) { $chyby[] = $inv['cislo'] . ': ' . $b['error']; continue; }
                $items[] = ['id' => 'fa-' . $inv['id'], 'xml' => $b['xml']];
                $vBalicku[] = $inv['id'];
                $zahrnute[] = $inv;
            }
            if (!$items) json_error('Nelze vytvořit XML: ' . implode(' | ', $chyby), 400);
            $xml = pohoda_datapack($items, (string) $cfg['pohoda_ico'], 'appek-' . date('YmdHis'), 'APPEK export faktur');
            foreach ($zahrnute as $inv) {
                // úspěšné přímé odeslání (mServer, s ID z POHODY) nepřepisujeme pouhým stažením souboru
                $st = ucetni_state_get($pdo, 'pohoda', $inv['id']);
                if ($st && !empty($st['ok_at']) && $st['rezim'] === 'mserver' && hash_equals((string) $st['hash'], $inv['hash'])) continue;
                ucetni_state_set($pdo, 'pohoda', $inv['id'], true, 'soubor', null, $inv['cislo'], $inv['hash']);
            }
            ucetni_log($pdo, 'pohoda', 'xml_soubor', true, 'XML pro ruční import: ' . count($items) . ' dokladů' . ($chyby ? '; vynecháno: ' . implode(' | ', $chyby) : ''),
                       'faktura', $fid ?: null, $fid ? ($zahrnute[0]['cislo'] ?? null) : null);
            if ($chyby) header('X-Ucetni-Vynechano: ' . rawurlencode(implode(' | ', $chyby)));
            ucetni_download($xml, $nazev . '.xml');
        }

        if ($action === 'pohoda_send') {
            $fid = (int) ($d['faktura_id'] ?? 0);
            $inv = $fid ? ucetni_nacti_fakturu($pdo, $fid) : null;
            if (!$inv) json_error('Faktura nenalezena', 404);
            if ($e = pohoda_cfg_chyba($cfg)) json_error($e, 400);
            $idem = ucetni_idempotence(ucetni_state_get($pdo, 'pohoda', $fid), $inv['hash'], !empty($d['force']), 'mserver');
            if (!$idem['pokracovat']) json_response(['ok' => false, 'needs_force' => true, 'duvod' => $idem['duvod'], 'zprava' => $idem['zprava']]);
            $v = pohoda_odeslat_faktury($pdo, $cfg, [$inv])[0] ?? ['ok' => false, 'zprava' => 'Neznámý výsledek'];
            json_response($v + ['varovani' => $inv['varovani']]);
        }

        if ($action === 'pohoda_send_bulk') {
            if ($e = pohoda_cfg_chyba($cfg)) json_error($e, 400);
            [$od, $do] = ucetni_obdobi($d);
            $ids = ucetni_faktury_v_obdobi($pdo, 'pohoda', $od, $do, !empty($d['jen_neodeslane']), 200);
            [$davka, $presk] = ucetni_priprav_davku($pdo, 'pohoda', $ids, !empty($d['force']));
            $v = $davka ? pohoda_odeslat_faktury($pdo, $cfg, $davka) : [];
            json_response(ucetni_souhrn($v, $presk));
        }

        if ($action === 'pohoda_adresar') {
            $rezim = ($d['rezim'] ?? 'soubor') === 'odeslat' ? 'odeslat' : 'soubor';
            if (($cfg['pohoda_ico'] ?? '') === '') json_error('Nejdřív vyplň IČO účetní jednotky v POHODĚ.', 400);
            if ($rezim === 'odeslat' && ($e = pohoda_cfg_chyba($cfg))) json_error($e, 400);
            $rows = $pdo->query("SELECT id, cislo, nazev, ico, dic, ulice, mesto, psc, email, telefon, web, splatnost_dni
                                 FROM odberatele ORDER BY id LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) json_error('Žádní odběratelé k exportu.', 404);
            $items = [];
            foreach ($rows as $o) $items[] = ['id' => 'odb-' . $o['id'], 'xml' => pohoda_xml_addressbook($o), 'nazev' => $o['nazev']];
            if ($rezim === 'soubor') {
                $xml = pohoda_datapack($items, (string) $cfg['pohoda_ico'], 'appek-adr-' . date('YmdHis'), 'APPEK adresář');
                ucetni_log($pdo, 'pohoda', 'adresar_soubor', true, 'XML adresáře: ' . count($items) . ' odběratelů');
                ucetni_download($xml, 'pohoda_adresar_' . date('Ymd') . '.xml');
            }
            $ok = 0; $chyby = [];
            foreach (array_chunk($items, 50) as $chunk) {
                $pack = pohoda_datapack($chunk, (string) $cfg['pohoda_ico'], 'appek-adr-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)), 'APPEK adresář');
                $r = pohoda_send($cfg, $pack, 120);
                if (!$r['parsed']) { $chyby[] = $r['error']; break; }
                $map = array_column($chunk, 'nazev', 'id');
                foreach ($r['parsed']['items'] as $it) {
                    if (in_array($it['state'], ['ok', 'warning'], true)) $ok++;
                    else $chyby[] = ($map[$it['id']] ?? $it['id']) . ': ' . (pohoda_item_messages($it) ?: 'chyba');
                }
            }
            ucetni_log($pdo, 'pohoda', 'adresar', !$chyby, "Adresář: $ok OK" . ($chyby ? ', chyby: ' . implode(' | ', array_slice($chyby, 0, 20)) : ''));
            json_response(['ok' => !$chyby || $ok > 0, 'odeslano' => $ok, 'chyby' => array_slice($chyby, 0, 50)]);
        }

        if ($action === 'pohoda_prijate') {
            if ($e = pohoda_cfg_chyba($cfg)) json_error($e, 400);
            [$od, $do] = ucetni_obdobi($_GET);
            $pack = pohoda_datapack([['id' => 'list-prijate', 'xml' => pohoda_xml_list_received($od, $do)]], (string) $cfg['pohoda_ico'], 'appek-list-' . date('YmdHis'), 'APPEK přijaté faktury');
            $r = pohoda_send($cfg, $pack, 90);
            if (!$r['ok']) {
                ucetni_log($pdo, 'pohoda', 'prijate', false, (string) $r['error']);
                json_error($r['error'], 502);
            }
            $it = $r['parsed']['items'][0] ?? null;
            if (!$it || !in_array($it['state'], ['ok', 'warning'], true)) {
                $err = $it ? (pohoda_item_messages($it) ?: 'POHODA požadavek odmítla.') : 'Prázdná odpověď POHODY.';
                ucetni_log($pdo, 'pohoda', 'prijate', false, $err);
                json_error($err, 502);
            }
            ucetni_log($pdo, 'pohoda', 'prijate', true, count($it['invoices']) . " přijatých faktur ($od – $do)");
            json_response(['ok' => true, 'faktury' => $it['invoices']]);
        }
    }

    // ═════════════════════════ FlexiBee ═════════════════════════
    if (str_starts_with($action, 'flexi_')) {
        $cfg = ucetni_settings($pdo, 'flexibee');
        $needEnabled = $action !== 'flexi_prijate';
        if ($e = flexi_cfg_chyba($cfg, $needEnabled)) json_error($e, 400);

        if ($action === 'flexi_send') {
            $fid = (int) ($d['faktura_id'] ?? 0);
            $inv = $fid ? ucetni_nacti_fakturu($pdo, $fid) : null;
            if (!$inv) json_error('Faktura nenalezena', 404);
            $idem = ucetni_idempotence(ucetni_state_get($pdo, 'flexibee', $fid), $inv['hash'], !empty($d['force']));
            if (!$idem['pokracovat']) json_response(['ok' => false, 'needs_force' => true, 'duvod' => $idem['duvod'], 'zprava' => $idem['zprava']]);
            json_response(flexi_odeslat_fakturu($pdo, $cfg, $inv) + ['varovani' => $inv['varovani']]);
        }

        if ($action === 'flexi_send_bulk') {
            [$od, $do] = ucetni_obdobi($d);
            $ids = ucetni_faktury_v_obdobi($pdo, 'flexibee', $od, $do, !empty($d['jen_neodeslane']), 200);
            [$davka, $presk] = ucetni_priprav_davku($pdo, 'flexibee', $ids, !empty($d['force']));
            $v = [];
            foreach ($davka as $inv) {
                $v[] = flexi_odeslat_fakturu($pdo, $cfg, $inv);
                $last = end($v);
                if (!$last['ok'] && preg_match('/HTTP 40[13]|přihlášení|429/u', (string) $last['zprava'])) break; // nemá smysl pokračovat
            }
            json_response(ucetni_souhrn($v, $presk));
        }

        if ($action === 'flexi_adresar') {
            $rows = $pdo->query("SELECT id, cislo, nazev, ico, dic, ulice, mesto, psc, email, telefon, splatnost_dni
                                 FROM odberatele ORDER BY id LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) json_error('Žádní odběratelé k exportu.', 404);
            $ok = 0; $chyby = [];
            foreach (array_chunk($rows, 100) as $chunk) {
                $r = flexi_upsert($cfg, 'adresar', array_map('flexi_build_partner', $chunk), 120);
                if ($r['ok']) { $ok += count($chunk); continue; }
                $chyby[] = $r['error'];
                if ($r['http'] === 401 || $r['http'] === 403 || $r['http'] === 0) break;
            }
            ucetni_log($pdo, 'flexibee', 'adresar', !$chyby, "Adresář: $ok odběratelů" . ($chyby ? '; chyby: ' . implode(' | ', $chyby) : ''));
            json_response(['ok' => !$chyby, 'odeslano' => $ok, 'chyby' => $chyby]);
        }

        if ($action === 'flexi_cenik') {
            $rows = $pdo->query("SELECT v.id, v.cislo, v.nazev, v.cena_bez_dph, v.ean, COALESCE(s.sazba, 12) AS sazba
                                 FROM vyrobky v LEFT JOIN sazby_dph s ON s.id = v.sazba_dph_id
                                 WHERE v.aktivni = 1 ORDER BY v.id LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) json_error('Žádné aktivní výrobky k exportu.', 404);
            $ok = 0; $chyby = [];
            foreach (array_chunk($rows, 100) as $chunk) {
                $r = flexi_upsert($cfg, 'cenik', array_map('flexi_build_product', $chunk), 120);
                if ($r['ok']) { $ok += count($chunk); continue; }
                $chyby[] = $r['error'];
                if ($r['http'] === 401 || $r['http'] === 403 || $r['http'] === 0) break;
            }
            ucetni_log($pdo, 'flexibee', 'cenik', !$chyby, "Ceník: $ok výrobků" . ($chyby ? '; chyby: ' . implode(' | ', $chyby) : ''));
            json_response(['ok' => !$chyby, 'odeslano' => $ok, 'chyby' => $chyby]);
        }

        if ($action === 'flexi_prijate') {
            [$od, $do] = ucetni_obdobi($_GET);
            $r = flexi_prijate($cfg, $od, $do);
            ucetni_log($pdo, 'flexibee', 'prijate', $r['ok'], $r['ok'] ? count($r['faktury']) . " přijatých faktur ($od – $do)" : $r['error']);
            if (!$r['ok']) json_error($r['error'], 502);
            json_response($r);
        }
    }

    json_error('Neznámá akce', 400);
} catch (Throwable $e) {
    json_error_safe('Účetní integrace: interní chyba', $e, 500);
}
