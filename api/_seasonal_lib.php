<?php
/**
 * 🍰 v3.0.331 — Sdílená logika SEZÓN (Sezónní balíček).
 * 🆕 v3.0.406 — předobjednávky (predstih_dni), jednorázové akce (start_full/end_full
 *   s konkrétním rokem), auto-přepočet pohyblivých svátků (auto_letos — Velikonoce
 *   computus, Den matek 2. neděle května), VÍCE sezón na výrobku (CSV ve vyrobky.sezona),
 *   seasonal_meta() pro countdown/report/POS.
 *
 * Jeden zdroj pravdy pro:
 *   - definice sezón: 6 výchozích (PHP, editovatelné přes overridy) + vlastní (seasons_custom),
 *   - aktivní okno sezóny dnes — VČETNĚ přechodu přes Nový rok (12-15 → 02-15),
 *   - předobjednávkové okno (predstih_dni PŘED startem),
 *   - sezónní úpravu ceny (sleva/přirážka %) per sezóna.
 *
 * Používá:
 *   - katalog.php           → filtr viditelnosti + cena + meta (countdown/preorder),
 *   - config.php cenik_pro_odberatele → cena (přihlášený odběratel → objednávka/faktura),
 *   - admin_pos.php catalog → filtr + cena pro POS (v406),
 *   - objednavky.php        → guard data dodání u předobjednávek (v406),
 *   - admin_seasonal.php    → správa sezón + report + predikce výroby.
 */

/** 6 výchozích sezón (klíče rezervované, definice fixní; slevu lze nastavit zvlášť). */
function seasonal_default_defs(): array {
    return [
        ['key' => 'velikonoce', 'label' => '🐰 Velikonoce',  'start_md' => '03-15', 'end_md' => '04-15', 'color' => '#FBBF24'],
        ['key' => 'denmatek',   'label' => '🌷 Den matek',    'start_md' => '05-01', 'end_md' => '05-15', 'color' => '#F472B6'],
        ['key' => 'valentyn',   'label' => '💝 Sv. Valentýn', 'start_md' => '02-01', 'end_md' => '02-14', 'color' => '#EF4444'],
        ['key' => 'haloween',   'label' => '🎃 Halloween',    'start_md' => '10-15', 'end_md' => '11-02', 'color' => '#EA580C'],
        ['key' => 'vanoce',     'label' => '🎄 Vánoce',       'start_md' => '12-01', 'end_md' => '12-31', 'color' => '#16A34A'],
        ['key' => 'mikulas',    'label' => '🎅 Mikuláš',      'start_md' => '11-25', 'end_md' => '12-06', 'color' => '#DC2626'],
    ];
}

/** Idempotentní migrace sloupců seasons_custom. */
function seasonal_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM seasons_custom")->fetchAll(PDO::FETCH_COLUMN);
        if (!$cols) return;
        if (!in_array('sleva_pct', $cols, true)) {
            $pdo->exec("ALTER TABLE seasons_custom ADD COLUMN sleva_pct DECIMAL(6,2) NOT NULL DEFAULT 0");
        }
        // 🆕 v3.0.406 — předobjednávky + jednorázové akce s konkrétním datem
        if (!in_array('predstih_dni', $cols, true)) {
            $pdo->exec("ALTER TABLE seasons_custom ADD COLUMN predstih_dni INT NOT NULL DEFAULT 0");
        }
        if (!in_array('start_full', $cols, true)) {
            $pdo->exec("ALTER TABLE seasons_custom ADD COLUMN start_full DATE NULL DEFAULT NULL, ADD COLUMN end_full DATE NULL DEFAULT NULL");
        }
    } catch (Throwable $e) { /* tabulka ještě neexistuje — vytvoří ji admin_seasonal.php */ }
}

/** Slevy výchozích sezón (klíč → pct) z nastaveni.seasonal_default_sleva (JSON). */
function seasonal_default_sleva_map(PDO $pdo): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    try {
        $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'seasonal_default_sleva' LIMIT 1");
        $st->execute();
        $raw = $st->fetchColumn();
        if ($raw) {
            $j = json_decode($raw, true);
            if (is_array($j)) foreach ($j as $k => $v) $m[(string) $k] = (float) $v;
        }
    } catch (Throwable $e) {}
    return $m;
}

/** Vlastní sezóny z DB (aktivní), vč. sleva_pct/predstih/full dat. */
function seasonal_custom_rows(PDO $pdo): array {
    static $rows = null;
    if ($rows !== null) return $rows;
    seasonal_ensure_schema($pdo);
    try {
        $rows = $pdo->query("
            SELECT id, sezona_key, label, start_md, end_md, color, aktivni,
                   COALESCE(sleva_pct, 0) AS sleva_pct,
                   COALESCE(predstih_dni, 0) AS predstih_dni,
                   start_full, end_full
            FROM seasons_custom WHERE aktivni = 1 ORDER BY label
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
    return $rows ?: [];
}

/** 🆕 v3.0.339 — přepis výchozích sezón z nastaveni.seasonal_default_overrides. */
function seasonal_default_overrides(PDO $pdo): array {
    static $o = null;
    if ($o !== null) return $o;
    $o = [];
    try {
        $st = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = 'seasonal_default_overrides' LIMIT 1");
        $st->execute();
        $raw = $st->fetchColumn();
        if ($raw) { $j = json_decode($raw, true); if (is_array($j)) $o = $j; }
    } catch (Throwable $e) {}
    return $o;
}

/** 🆕 v3.0.406 — Velikonoční neděle (Meeus/Jones/Butcher computus), bez ext-calendar. */
function seasonal_easter_sunday(int $year): string {
    $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** 🆕 v3.0.406 — 2. neděle v květnu (Den matek). */
function seasonal_mothers_day(int $year): string {
    $t = strtotime("second sunday of may $year");
    return date('Y-m-d', $t);
}

/** Všechny sezóny (výchozí + vlastní) s jednotným tvarem. */
function seasonal_all(PDO $pdo): array {
    $dmap = seasonal_default_sleva_map($pdo);
    $ovr = seasonal_default_overrides($pdo);
    $out = [];
    foreach (seasonal_default_defs() as $s) {
        $o = $ovr[$s['key']] ?? [];
        if (!empty($o['label']))    $s['label']    = (string) $o['label'];
        if (!empty($o['start_md']) && preg_match('/^\d{2}-\d{2}$/', (string) $o['start_md'])) $s['start_md'] = $o['start_md'];
        if (!empty($o['end_md'])   && preg_match('/^\d{2}-\d{2}$/', (string) $o['end_md']))   $s['end_md']   = $o['end_md'];
        if (!empty($o['color']))    $s['color']    = (string) $o['color'];
        $s['is_default']   = true;
        $s['has_override'] = !empty($o);
        $s['sleva_pct']    = isset($o['sleva_pct']) ? (float) $o['sleva_pct'] : (float) ($dmap[$s['key']] ?? 0);
        $s['predstih_dni'] = isset($o['predstih_dni']) ? max(0, (int) $o['predstih_dni']) : 0;
        $s['auto_letos']   = !empty($o['auto_letos']);
        $s['start_full']   = null;
        $s['end_full']     = null;
        // 🆕 v3.0.406 — auto-přepočet pohyblivých svátků na AKTUÁLNÍ rok
        if ($s['auto_letos']) {
            $y = (int) date('Y');
            if ($s['key'] === 'velikonoce') {
                $es = seasonal_easter_sunday($y);
                // okno dle v342: Velikonoce −28 dní → +1 den
                $s['start_md'] = date('m-d', strtotime($es . ' -28 days'));
                $s['end_md']   = date('m-d', strtotime($es . ' +1 day'));
            } elseif ($s['key'] === 'denmatek') {
                $md = seasonal_mothers_day($y);
                $s['start_md'] = date('m-d', strtotime($md . ' -12 days'));
                $s['end_md']   = date('m-d', strtotime($md));
            }
        }
        $out[] = $s;
    }
    foreach (seasonal_custom_rows($pdo) as $r) {
        $out[] = [
            'id'           => (int) $r['id'],
            'key'          => $r['sezona_key'],
            'label'        => $r['label'],
            'start_md'     => $r['start_md'],
            'end_md'       => $r['end_md'],
            'color'        => $r['color'],
            'is_default'   => false,
            'sleva_pct'    => (float) $r['sleva_pct'],
            'predstih_dni' => max(0, (int) ($r['predstih_dni'] ?? 0)),
            'auto_letos'   => false,
            'start_full'   => $r['start_full'] ?: null,
            'end_full'     => $r['end_full'] ?: null,
        ];
    }
    return $out;
}

/** Je sezóna aktivní na daný den (MM-DD)? Řeší přechod přes Nový rok. (Ponecháno pro zpětnou kompatibilitu.) */
function seasonal_is_active(array $s, string $md): bool {
    if ($s['start_md'] <= $s['end_md']) {
        return $md >= $s['start_md'] && $md <= $s['end_md'];
    }
    return $md >= $s['start_md'] || $md <= $s['end_md']; // wrap 12-15 → 02-15
}

/**
 * 🆕 v3.0.406 — plné rozlišení okna sezóny k danému dni.
 * Vrací ['start','end' (Y-m-d aktuálního cyklu), 'active', 'preorder',
 *        'days_left' (aktivní: 0 = končí dnes), 'starts_in' (dní do startu)].
 * Jednorázová akce (start_full/end_full) má přednost před opakovaným MM-DD.
 */
function seasonal_resolve_window(array $s, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $pre = max(0, (int) ($s['predstih_dni'] ?? 0));
    $mk = function (?string $start, ?string $end) use ($today, $pre) {
        $active = $start !== null && $today >= $start && $today <= $end;
        $preStart = $start !== null ? date('Y-m-d', strtotime($start . " -{$pre} days")) : null;
        $preorder = !$active && $pre > 0 && $start !== null && $today >= $preStart && $today < $start;
        return [
            'start' => $start, 'end' => $end,
            'active' => $active, 'preorder' => $preorder,
            'days_left' => $active ? (int) round((strtotime($end) - strtotime($today)) / 86400) : null,
            'starts_in' => (!$active && $start !== null && $today < $start) ? (int) round((strtotime($start) - strtotime($today)) / 86400) : null,
        ];
    };
    // Jednorázová akce s konkrétním rokem
    if (!empty($s['start_full']) && !empty($s['end_full'])) {
        return $mk($s['start_full'], $s['end_full']);
    }
    $y = (int) substr($today, 0, 4);
    $sm = $s['start_md']; $em = $s['end_md'];
    if ($sm <= $em) {
        $w = $mk("$y-$sm", "$y-$em");
        if (!$w['active'] && !$w['preorder'] && $today > "$y-$em") {
            // letos už proběhla → další cyklus příští rok (kvůli starts_in)
            $y2 = $y + 1;
            $w = $mk("$y2-$sm", "$y2-$em");
        }
        return $w;
    }
    // wrap přes Nový rok (např. 12-15 → 02-15)
    $md = substr($today, 5);
    if ($md >= $sm)  return $mk("$y-$sm", ($y + 1) . "-$em");          // v „zimní" části
    if ($md <= $em)  return $mk(($y - 1) . "-$sm", "$y-$em");          // v „jarní" části
    return $mk("$y-$sm", ($y + 1) . "-$em");                           // před startem letos
}

/** 🆕 v3.0.406 — rozdělení CSV pole vyrobky.sezona na klíče ("vanoce,mikulas"). */
function seasonal_split(?string $field): array {
    if ($field === null || trim($field) === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $field)), fn($k) => $k !== ''));
}

/** 🆕 v3.0.406 — meta všech sezón (cache per-request): resolve_window + sleva. */
function seasonal_meta(PDO $pdo, ?string $today = null): array {
    static $meta = null;
    if ($meta !== null && $today === null) return $meta;
    $out = [];
    foreach (seasonal_all($pdo) as $s) {
        $w = seasonal_resolve_window($s, $today);
        $out[$s['key']] = array_merge($w, [
            'key' => $s['key'], 'label' => $s['label'], 'color' => $s['color'],
            'sleva_pct' => (float) $s['sleva_pct'], 'predstih_dni' => (int) ($s['predstih_dni'] ?? 0),
            'is_default' => !empty($s['is_default']),
        ]);
    }
    if ($today === null) $meta = $out;
    return $out;
}

/** Klíče sezón aktivních v daný den (default dnes). */
function seasonal_active_keys(PDO $pdo, ?string $date = null): array {
    $keys = [];
    foreach (seasonal_meta($pdo, $date) as $k => $m) {
        if ($m['active']) $keys[] = $k;
    }
    return $keys;
}

/** 🆕 v3.0.406 — klíče v předobjednávkovém okně (predstih_dni před startem). */
function seasonal_preorder_keys(PDO $pdo, ?string $date = null): array {
    $keys = [];
    foreach (seasonal_meta($pdo, $date) as $k => $m) {
        if ($m['preorder']) $keys[] = $k;
    }
    return $keys;
}

/** 🆕 v3.0.406 — klíče viditelné v katalogu = aktivní ∪ předobjednávka. */
function seasonal_visible_keys(PDO $pdo, ?string $date = null): array {
    return array_values(array_unique(array_merge(seasonal_active_keys($pdo, $date), seasonal_preorder_keys($pdo, $date))));
}

/** 🆕 v3.0.406 — je výrobek (jeho sezona CSV pole) viditelný pro danou množinu klíčů? */
function seasonal_visible(?string $field, array $allowedKeys): bool {
    $keys = seasonal_split($field);
    if (!$keys) return true; // nesezónní výrobek
    $set = array_flip($allowedKeys);
    foreach ($keys as $k) { if (isset($set[$k])) return true; }
    return false;
}

/** Kontext pro cenu (cache per-request): aktivní klíče dnes + mapa slev (gated balíčkem). */
function seasonal_pricing_context(PDO $pdo): array {
    static $ctx = null;
    if ($ctx !== null) return $ctx;
    $active = [];
    $pct = [];
    if (!function_exists('package_enabled')) {
        $pl = __DIR__ . '/_packages_lib.php';
        if (is_file($pl)) { try { require_once $pl; } catch (Throwable $e) {} }
    }
    $pricingOn = !function_exists('package_enabled') || package_enabled('sezona');
    foreach (seasonal_meta($pdo) as $k => $m) {
        if (!$m['active']) continue;
        $active[$k] = true;
        if ($pricingOn && (float) $m['sleva_pct'] != 0.0) $pct[$k] = (float) $m['sleva_pct'];
    }
    $ctx = ['active' => $active, 'pct' => $pct];
    return $ctx;
}

/**
 * Uprav cenu dle sezóny. Vrací ['cena' => float, 'pct' => float|null].
 * pct > 0 = sleva, pct < 0 = přirážka. Jen když je sezóna PRÁVĚ aktivní.
 * 🆕 v3.0.406 — CSV: první AKTIVNÍ klíč s nenulovou úpravou vyhrává.
 */
function seasonal_adjust_price(PDO $pdo, float $base, ?string $sezona): array {
    $keys = seasonal_split($sezona);
    if (!$keys) return ['cena' => $base, 'pct' => null];
    $ctx = seasonal_pricing_context($pdo);
    foreach ($keys as $k) {
        if (isset($ctx['pct'][$k])) {
            $p = (float) $ctx['pct'][$k];
            return ['cena' => round($base * (1 - $p / 100), 2), 'pct' => $p];
        }
    }
    return ['cena' => $base, 'pct' => null];
}

/**
 * 🆕 v3.0.406 — meta pro konkrétní výrobek (CSV pole sezona) pro katalog/B2B:
 * ['konci_za' => int|null (nejbližší konec z aktivních), 'predobjednavka' => bool
 *  (viditelný JEN přes preorder), 'dodani_od' => 'Y-m-d'|null (start nejbližší preorder sezóny)].
 */
function seasonal_product_meta(PDO $pdo, ?string $field): array {
    $keys = seasonal_split($field);
    if (!$keys) return ['konci_za' => null, 'predobjednavka' => false, 'dodani_od' => null];
    $meta = seasonal_meta($pdo);
    $anyActive = false; $minLeft = null; $preStart = null;
    foreach ($keys as $k) {
        $m = $meta[$k] ?? null;
        if (!$m) continue;
        if ($m['active']) {
            $anyActive = true;
            if ($m['days_left'] !== null && ($minLeft === null || $m['days_left'] < $minLeft)) $minLeft = $m['days_left'];
        } elseif ($m['preorder']) {
            if ($preStart === null || $m['start'] < $preStart) $preStart = $m['start'];
        }
    }
    return [
        'konci_za'       => $anyActive ? $minLeft : null,
        'predobjednavka' => !$anyActive && $preStart !== null,
        'dodani_od'      => !$anyActive ? $preStart : null,
    ];
}
