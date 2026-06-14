<?php
/**
 * 🍰 v3.0.331 — Sdílená logika SEZÓN (Sezónní balíček).
 *
 * Jeden zdroj pravdy pro:
 *   - definice sezón: 6 výchozích (PHP) + vlastní (DB tabulka seasons_custom),
 *   - aktivní okno sezóny dnes — VČETNĚ přechodu přes Nový rok (12-15 → 02-15),
 *   - sezónní úpravu ceny (sleva/přirážka %) per sezóna.
 *
 * Používá:
 *   - katalog.php           → filtr viditelnosti + cena (anonym/admin pohled),
 *   - config.php cenik_pro_odberatele → cena (přihlášený odběratel → i objednávka/faktura),
 *   - admin_seasonal.php    → správa sezón + slev.
 *
 * Před v3.0.331 byl filtr natvrdo jen pro 6 výchozích sezón v katalog.php a na
 * přihlášeného odběratele se NEAPLIKOVAL vůbec → vlastní sezóny „nešly". Tohle to sjednocuje.
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

/** Idempotentně doplní sloupec sleva_pct do seasons_custom (pokud tabulka existuje). */
function seasonal_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM seasons_custom")->fetchAll(PDO::FETCH_COLUMN);
        if ($cols && !in_array('sleva_pct', $cols, true)) {
            $pdo->exec("ALTER TABLE seasons_custom ADD COLUMN sleva_pct DECIMAL(6,2) NOT NULL DEFAULT 0");
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

/** Vlastní sezóny z DB (aktivní), vč. sleva_pct. */
function seasonal_custom_rows(PDO $pdo): array {
    static $rows = null;
    if ($rows !== null) return $rows;
    seasonal_ensure_schema($pdo);
    try {
        $rows = $pdo->query("
            SELECT id, sezona_key, label, start_md, end_md, color, aktivni, COALESCE(sleva_pct, 0) AS sleva_pct
            FROM seasons_custom WHERE aktivni = 1 ORDER BY label
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
    return $rows ?: [];
}

/** Všechny sezóny (výchozí + vlastní) s jednotným tvarem, vč. is_default a sleva_pct. */
function seasonal_all(PDO $pdo): array {
    $dmap = seasonal_default_sleva_map($pdo);
    $out = [];
    foreach (seasonal_default_defs() as $s) {
        $s['is_default'] = true;
        $s['sleva_pct']  = (float) ($dmap[$s['key']] ?? 0);
        $out[] = $s;
    }
    foreach (seasonal_custom_rows($pdo) as $r) {
        $out[] = [
            'id'        => (int) $r['id'],
            'key'       => $r['sezona_key'],
            'label'     => $r['label'],
            'start_md'  => $r['start_md'],
            'end_md'    => $r['end_md'],
            'color'     => $r['color'],
            'is_default' => false,
            'sleva_pct' => (float) $r['sleva_pct'],
        ];
    }
    return $out;
}

/** Je sezóna aktivní na daný den (MM-DD)? Řeší přechod přes Nový rok. */
function seasonal_is_active(array $s, string $md): bool {
    if ($s['start_md'] <= $s['end_md']) {
        return $md >= $s['start_md'] && $md <= $s['end_md'];
    }
    return $md >= $s['start_md'] || $md <= $s['end_md']; // wrap 12-15 → 02-15
}

/** Klíče sezón aktivních v daný den (default dnes). */
function seasonal_active_keys(PDO $pdo, ?string $date = null): array {
    $md = substr($date ?: date('Y-m-d'), 5);
    $keys = [];
    foreach (seasonal_all($pdo) as $s) {
        if (seasonal_is_active($s, $md)) $keys[] = $s['key'];
    }
    return $keys;
}

/** Kontext pro cenu (cache per-request): aktivní klíče dnes + mapa slev (gated balíčkem). */
function seasonal_pricing_context(PDO $pdo): array {
    static $ctx = null;
    if ($ctx !== null) return $ctx;
    $active = [];
    $pct = [];
    // Sezónní cena je add-on balíček → respektuj licenci (po vypršení se vypne). Lazy-load,
    // ať gating platí i na veřejném katalogu (kde se _packages_lib jinak nenačítá).
    if (!function_exists('package_enabled')) {
        $pl = __DIR__ . '/_packages_lib.php';
        if (is_file($pl)) { try { require_once $pl; } catch (Throwable $e) {} }
    }
    $pricingOn = !function_exists('package_enabled') || package_enabled('sezona');
    $md = date('m-d');
    foreach (seasonal_all($pdo) as $s) {
        if (!seasonal_is_active($s, $md)) continue;
        $active[$s['key']] = true;
        if ($pricingOn && (float) $s['sleva_pct'] != 0.0) $pct[$s['key']] = (float) $s['sleva_pct'];
    }
    $ctx = ['active' => $active, 'pct' => $pct];
    return $ctx;
}

/**
 * Uprav cenu dle sezóny. Vrací ['cena' => float, 'pct' => float|null].
 * pct > 0 = sleva (cena nižší), pct < 0 = přirážka (cena vyšší). Aplikuje se jen
 * když je sezóna PRÁVĚ aktivní a má nastavenou nenulovou úpravu.
 */
function seasonal_adjust_price(PDO $pdo, float $base, ?string $sezona): array {
    if (!$sezona) return ['cena' => $base, 'pct' => null];
    $ctx = seasonal_pricing_context($pdo);
    if (!isset($ctx['pct'][$sezona])) return ['cena' => $base, 'pct' => null];
    $p = (float) $ctx['pct'][$sezona];
    return ['cena' => round($base * (1 - $p / 100), 2), 'pct' => $p];
}
